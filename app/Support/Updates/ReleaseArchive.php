<?php

namespace App\Support\Updates;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Reads a downloaded release zip. Every entry is checked before anything is
 * written, and files are unpacked one by one in portions, because a release
 * has too many files for a single request on shared hosting.
 */
final class ReleaseArchive
{
    private const ROOT = 'opengallery/';

    /** Files a working release cannot be without. */
    private const REQUIRED = ['VERSION', 'artisan', 'bootstrap/app.php', 'public/index.php', 'public/build/manifest.json', 'vendor/autoload.php'];

    /** @throws UpdateFailed when the zip does not match its manifest or holds an unsafe entry */
    public static function validate(string $zipPath, ReleaseManifest $manifest): void
    {
        $zip = self::open($zipPath);
        $invalid = new UpdateFailed(__('The downloaded release is not valid.'));
        $names = [];
        $files = 0;
        $bytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? (string) $stat['name'] : '';

            if (! self::isSafe($zip, $index, $name)) {
                $zip->close();

                throw $invalid;
            }

            if (! str_ends_with($name, '/')) {
                $files++;
                $bytes += (int) $stat['size'];
                $names[substr($name, strlen(self::ROOT))] = true;
            }
        }

        $version = trim((string) $zip->getFromName(self::ROOT.'VERSION'));
        $zip->close();

        if ($files !== $manifest->files || $bytes !== $manifest->unpackedSize || $version !== $manifest->version
            || array_diff(self::REQUIRED, array_keys($names)) !== []) {
            throw $invalid;
        }
    }

    /**
     * Unpacks entries from $from on until $deadline.
     *
     * @return int the next entry to unpack; equal to count() when finished
     *
     * @throws UpdateFailed
     */
    public static function extract(string $zipPath, string $staging, int $from, float $deadline): int
    {
        $zip = self::open($zipPath);
        $index = $from;

        try {
            for (; $index < $zip->numFiles; $index++) {
                if ($index > $from && microtime(true) >= $deadline) {
                    break;
                }

                $name = (string) $zip->getNameIndex($index);
                $target = $staging.'/'.substr($name, strlen(self::ROOT));

                if (str_ends_with($name, '/')) {
                    File::ensureDirectoryExists($target);

                    continue;
                }

                File::ensureDirectoryExists(dirname($target));
                $in = $zip->getStreamIndex($index);
                $out = @fopen($target, 'wb');

                if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
                    throw new UpdateFailed(__('The release could not be unpacked. Check that the server has enough disk space.'));
                }

                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }

        return $index;
    }

    /** @throws UpdateFailed */
    public static function count(string $zipPath): int
    {
        $zip = self::open($zipPath);
        $count = $zip->numFiles;
        $zip->close();

        return $count;
    }

    private static function isSafe(ZipArchive $zip, int $index, string $name): bool
    {
        if (! str_starts_with($name, self::ROOT) || str_contains($name, '\\') || str_contains($name, "\0")) {
            return false;
        }

        $relative = rtrim(substr($name, strlen(self::ROOT)), '/');

        if ($relative !== '' && array_intersect(explode('/', $relative), ['', '.', '..']) !== []) {
            return false;
        }

        return ! ($zip->getExternalAttributesIndex($index, $system, $attributes)
            && $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0xF000) === 0xA000);
    }

    /** @throws UpdateFailed */
    private static function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UpdateFailed(__('The downloaded release is not valid.'));
        }

        return $zip;
    }
}
