<?php

namespace App\Support;

use App\Models\Photo;
use App\Models\User;

/**
 * Removes GPS positions from photo files as they are sent to visitors. The
 * stored file never changes, so uploaders and people who may edit the photo
 * keep theirs.
 *
 * Positions are blanked byte for byte, so the image data and every other
 * piece of metadata stay exactly where they are: the GPS block of EXIF (in
 * JPEG, PNG, WebP, HEIC and AVIF) and GPS values in XMP.
 */
class PhotoLocation
{
    /** How much of a file is searched at once. */
    private const WINDOW_BYTES = 1048576;

    /** Repeated between windows, so a marker on the border is still found. */
    private const OVERLAP_BYTES = 4096;

    /** Copies up to this size stay in memory, larger ones go to a temporary file. */
    private const MEMORY_BYTES = 2097152;

    private const MAX_IFD_ENTRIES = 1024;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const PNG_XMP_KEYWORD = "XML:com.adobe.xmp\0";

    /** Bytes per value of each TIFF field type. */
    private const TIFF_TYPE_BYTES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8];

    private const TIFF_GPS_POINTER = 0x8825;

    /**
     * Whether $user gets this file without its position. Originals keep GPS
     * data; conversions only with Imagick, because GD writes no metadata.
     */
    public static function isHiddenFrom(?User $user, Photo $photo, bool $isOriginal = true): bool
    {
        if (! SiteSettings::removesPhotoLocation()) {
            return false;
        }

        if (! $isOriginal && config('media-library.image_driver') !== 'imagick') {
            return false;
        }

        return ! $user?->can('update', $photo);
    }

    /**
     * The file in $source without GPS data, as a readable stream at its
     * start. $source is closed when a copy is returned instead.
     *
     * @param  resource  $source
     * @return resource
     */
    public static function withoutLocation($source)
    {
        $file = $source;

        if (! stream_get_meta_data($source)['seekable']) {
            $file = fopen('php://temp/maxmemory:'.self::MEMORY_BYTES, 'w+b');
            stream_copy_to_stream($source, $file);
            fclose($source);
        }

        $patches = self::patches($file);
        rewind($file);

        if ($patches === []) {
            return $file;
        }

        $copy = fopen('php://temp/maxmemory:'.self::MEMORY_BYTES, 'w+b');
        stream_copy_to_stream($file, $copy);
        fclose($file);

        foreach ($patches as $offset => $bytes) {
            fseek($copy, $offset);
            fwrite($copy, $bytes);
        }

        rewind($copy);

        return $copy;
    }

    /**
     * What to overwrite in the file, always with as many bytes as were there.
     *
     * @param  resource  $file  seekable
     * @return array<int, string> bytes by file offset
     */
    private static function patches($file): array
    {
        $size = (int) fstat($file)['size'];
        $patches = self::searchedPatches($file, $size);
        $start = self::read($file, 0, 12);

        if (str_starts_with($start, self::PNG_SIGNATURE)) {
            return self::withPngChecksums($file, $size, $patches + self::pngMetadataPatches($file, $size));
        }

        if (str_starts_with($start, 'RIFF') && substr($start, 8, 4) === 'WEBP') {
            return $patches + self::webpExifPatches($file, $size);
        }

        return $patches;
    }

    /**
     * EXIF blocks that carry the usual "Exif" marker and XMP GPS values,
     * wherever they are in the file (HEIC and AVIF keep them in varying places).
     *
     * @param  resource  $file
     * @return array<int, string>
     */
    private static function searchedPatches($file, int $size): array
    {
        $patches = [];

        for ($position = 0; $position < $size; $position += self::WINDOW_BYTES) {
            $window = self::read($file, $position, self::WINDOW_BYTES + self::OVERLAP_BYTES);

            preg_match_all('/Exif\0\0(?=II\x2A\0|MM\0\x2A)/', $window, $markers, PREG_OFFSET_CAPTURE);

            foreach ($markers[0] as [$marker, $offset]) {
                $patches += self::gpsBlockPatches($file, $position + $offset + strlen($marker), $size);
            }

            $patches += self::xmpPatches($window, $position);
        }

        return $patches;
    }

    /**
     * XMP writes GPS values as attributes or elements of the exif namespace.
     *
     * @return array<int, string>
     */
    private static function xmpPatches(string $text, int $offset): array
    {
        $patches = [];

        preg_match_all(
            '/exif(?:EX)?:GPS[A-Za-z]+\s*=\s*(?:"([^"]{1,256})"|\'([^\']{1,256})\')|<exif(?:EX)?:GPS[A-Za-z]+[^>]{0,64}>([^<]{1,256})</',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );

        foreach ($matches as $match) {
            foreach (array_slice($match, 1) as [$value, $position]) {
                if ($value !== null) {
                    $patches[$offset + $position] = str_repeat(' ', strlen($value));
                }
            }
        }

        return $patches;
    }

    /**
     * Empties the GPS block of the TIFF structure at $tiff: its values and
     * its directory. The pointer to it stays, leading to an empty directory.
     *
     * @param  resource  $file
     * @return array<int, string>
     */
    private static function gpsBlockPatches($file, int $tiff, int $size): array
    {
        $header = self::read($file, $tiff, 8);

        if (strlen($header) < 8 || ! in_array(substr($header, 0, 4), ["II\x2A\0", "MM\0\x2A"], true)) {
            return [];
        }

        $littleEndian = $header[0] === 'I';
        $short = fn (string $bytes): int => unpack($littleEndian ? 'v' : 'n', $bytes)[1];
        $long = fn (string $bytes): int => unpack($littleEndian ? 'V' : 'N', $bytes)[1];

        $gps = null;

        foreach (self::ifdEntries($file, $tiff + $long(substr($header, 4, 4)), $short) as $entry) {
            if ($short(substr($entry, 0, 2)) === self::TIFF_GPS_POINTER) {
                $gps = $tiff + $long(substr($entry, 8, 4));
            }
        }

        if ($gps === null) {
            return [];
        }

        $entries = self::ifdEntries($file, $gps, $short);
        $directoryBytes = 2 + 12 * count($entries) + 4;

        if ($entries === [] || $gps + $directoryBytes > $size) {
            return [];
        }

        $patches = [];

        foreach ($entries as $entry) {
            $bytes = (self::TIFF_TYPE_BYTES[$short(substr($entry, 2, 2))] ?? 0) * $long(substr($entry, 4, 4));
            $offset = $tiff + $long(substr($entry, 8, 4));

            // Up to four bytes are stored in the entry itself, which is blanked below.
            if ($bytes > 4 && $offset + $bytes <= $size) {
                $patches[$offset] = str_repeat("\0", $bytes);
            }
        }

        $patches[$gps] = str_repeat("\0", $directoryBytes);

        return $patches;
    }

    /**
     * @param  resource  $file
     * @param  callable(string): int  $short
     * @return list<string> the directory's 12-byte entries; none if it is damaged
     */
    private static function ifdEntries($file, int $offset, callable $short): array
    {
        $countBytes = self::read($file, $offset, 2);

        if (strlen($countBytes) < 2) {
            return [];
        }

        $count = $short($countBytes);

        if ($count === 0 || $count > self::MAX_IFD_ENTRIES) {
            return [];
        }

        $entries = self::read($file, $offset + 2, 12 * $count);

        return strlen($entries) === 12 * $count ? str_split($entries, 12) : [];
    }

    /**
     * PNG's own EXIF chunk (no "Exif" marker) and compressed XMP, which
     * cannot be searched: its text is replaced by spaces.
     *
     * @param  resource  $file
     * @return array<int, string>
     */
    private static function pngMetadataPatches($file, int $size): array
    {
        $patches = [];

        foreach (self::pngChunks($file, $size) as [$type, $data, $length]) {
            if ($type === 'eXIf') {
                $patches += self::gpsBlockPatches($file, $data, $size);
            }

            if ($type === 'iTXt' && $length > strlen(self::PNG_XMP_KEYWORD) + 1) {
                $patches += self::compressedPngXmpPatches(self::read($file, $data, $length), $data);
            }
        }

        return $patches;
    }

    /** @return array<int, string> */
    private static function compressedPngXmpPatches(string $chunk, int $data): array
    {
        $flag = strlen(self::PNG_XMP_KEYWORD);

        if (! str_starts_with($chunk, self::PNG_XMP_KEYWORD) || $chunk[$flag] !== "\1") {
            return [];
        }

        // After the flag and method: language, NUL, translated keyword, NUL, text.
        $language = strpos($chunk, "\0", $flag + 2);
        $translated = $language === false ? false : strpos($chunk, "\0", $language + 1);

        if ($translated === false) {
            return [];
        }

        return [
            $data + $flag => "\0\0",
            $data + $translated + 1 => str_repeat(' ', strlen($chunk) - $translated - 1),
        ];
    }

    /**
     * PNG chunks carry a checksum, so every changed chunk needs a new one.
     *
     * @param  resource  $file
     * @param  array<int, string>  $patches
     * @return array<int, string>
     */
    private static function withPngChecksums($file, int $size, array $patches): array
    {
        if ($patches === []) {
            return [];
        }

        foreach (self::pngChunks($file, $size) as [, $data, $length]) {
            $inChunk = array_filter($patches, fn (int $offset) => $offset >= $data && $offset < $data + $length, ARRAY_FILTER_USE_KEY);

            if ($inChunk === []) {
                continue;
            }

            $bytes = self::read($file, $data - 4, $length + 4);

            foreach ($inChunk as $offset => $patch) {
                $bytes = substr_replace($bytes, $patch, $offset - $data + 4, strlen($patch));
            }

            $patches[$data + $length] = pack('N', crc32($bytes));
        }

        return $patches;
    }

    /**
     * @param  resource  $file
     * @return list<array{0: string, 1: int, 2: int}> type, offset of the data, length of the data
     */
    private static function pngChunks($file, int $size): array
    {
        $chunks = [];
        $position = strlen(self::PNG_SIGNATURE);

        while ($position + 12 <= $size) {
            $header = self::read($file, $position, 8);
            $length = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);

            if ($position + 12 + $length > $size) {
                break;
            }

            $chunks[] = [$type, $position + 8, $length];

            if ($type === 'IEND') {
                break;
            }

            $position += 12 + $length;
        }

        return $chunks;
    }

    /**
     * WebP's EXIF chunk, which usually has no "Exif" marker.
     *
     * @param  resource  $file
     * @return array<int, string>
     */
    private static function webpExifPatches($file, int $size): array
    {
        for ($position = 12; $position + 8 <= $size;) {
            $header = self::read($file, $position, 8);
            $length = unpack('V', substr($header, 4, 4))[1];

            if (substr($header, 0, 4) === 'EXIF') {
                return self::gpsBlockPatches($file, $position + 8, $size);
            }

            // Chunks are padded to an even length.
            $position += 8 + $length + ($length % 2);
        }

        return [];
    }

    /** @param  resource  $file */
    private static function read($file, int $offset, int $length): string
    {
        return (string) stream_get_contents($file, $length, $offset);
    }
}
