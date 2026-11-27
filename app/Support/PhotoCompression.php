<?php

namespace App\Support;

use App\Models\Photo;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Throwable;

/**
 * Shrinks photo files as set in Admin → Settings → Photos (SiteSettings):
 * scales them down to a longest side and/or saves JPEG and WebP at a lower
 * quality. The compressed file replaces the original; one that would not
 * get smaller is left as it was.
 *
 * The pixels keep the way they are stored, and camera, date, location and
 * colour profile metadata are carried over (Imagick keeps them itself; with
 * GD they are copied from the original file), so EXIF orientation and the
 * photo information views keep working.
 *
 * Only JPEG, WebP and PNG are written (PNG is lossless, so it is only
 * scaled down). GIF, HEIC, AVIF and animated WebP stay as they are.
 *
 * New uploads are compressed in App\Actions\AddPhotoToAlbum. Photos stored
 * before are compressed on request (startExisting()), a few at a time in
 * the background jobs (BackgroundJobs::tasks()).
 */
class PhotoCompression
{
    /** Media custom property: the settings a file was compressed with, as ['max' => ?int, 'quality' => ?int]. */
    public const PROPERTY = 'compression';

    /** Setting: while existing photos are being compressed, the id of the last media item looked at (0 at the start). */
    public const EXISTING_CURSOR = 'uploads.compression_existing';

    /** Photos looked at per pass. */
    public const BATCH = 50;

    /** Seconds one pass may spend. */
    public const SECONDS = 15;

    /** JPEG/WebP quality when a photo is only scaled down. */
    public const RESIZE_QUALITY = 90;

    private const FORMATS = ['image/jpeg' => 'jpeg', 'image/webp' => 'webp', 'image/png' => 'png'];

    /** JPEG segments with metadata: APP1 (EXIF, XMP), APP2 (ICC profile), APP13 (IPTC). */
    private const JPEG_METADATA_MARKERS = ["\xE1", "\xE2", "\xED"];

    /** PNG chunks with metadata, kept before the image data. */
    private const PNG_METADATA_CHUNKS = ['iCCP', 'eXIf', 'iTXt', 'tEXt', 'zTXt'];

    /** WebP chunks with metadata and their VP8X flag. */
    private const WEBP_METADATA_CHUNKS = ['ICCP' => 0x20, 'EXIF' => 0x08, 'XMP ' => 0x04];

    /** Whether new uploads are compressed: on, with at least one of its parts. */
    public static function isActive(): bool
    {
        return SiteSettings::compressionMaxPx() !== null || SiteSettings::compressionQuality() !== null;
    }

    /** @return array{max: ?int, quality: ?int} the settings in use now */
    public static function settings(): array
    {
        return ['max' => SiteSettings::compressionMaxPx(), 'quality' => SiteSettings::compressionQuality()];
    }

    /**
     * Whether a file of $width x $height that was compressed with $applied
     * (null: never) would change with the current settings.
     *
     * @param  array{max?: ?int, quality?: ?int}|null  $applied
     */
    public static function wouldChange(?int $width, ?int $height, ?array $applied): bool
    {
        ['max' => $max, 'quality' => $quality] = self::settings();

        if ($max !== null && max((int) $width, (int) $height) > $max) {
            return true;
        }

        if ($quality === null) {
            return false;
        }

        $appliedQuality = $applied['quality'] ?? null;

        return $appliedQuality === null || $quality < $appliedQuality;
    }

    /** Width and height of the image at $path as shown: swapped for photos stored a quarter turn off. */
    public static function shownSize(string $path, mixed $orientation): array
    {
        [$width, $height] = @getimagesize($path) ?: [null, null];

        if (in_array((int) $orientation, Photo::TURNED_ORIENTATIONS, true)) {
            [$width, $height] = [$height, $width];
        }

        return [$width, $height];
    }

    /** Starts (or starts over) compressing the photos already stored, with the current settings. */
    public static function startExisting(): void
    {
        Setting::put(self::EXISTING_CURSOR, 0);
    }

    /** Whether existing photos are waiting to be compressed. */
    public static function hasWork(): bool
    {
        return is_numeric(Setting::get(self::EXISTING_CURSOR)) && self::isActive();
    }

    /** How many stored photos are still to be looked at, or null when none are being compressed. */
    public static function remaining(): ?int
    {
        $cursor = Setting::get(self::EXISTING_CURSOR);

        return is_numeric($cursor) && self::isActive() ? self::existingQuery((int) $cursor)->count() : null;
    }

    /**
     * One background pass: compresses stored photos for up to $seconds and
     * stops the run once all were looked at (or compression was turned off).
     *
     * @return int how many files got smaller
     */
    public static function compressExisting(int $seconds = self::SECONDS): int
    {
        $cursor = Setting::get(self::EXISTING_CURSOR);

        if (! is_numeric($cursor)) {
            return 0;
        }

        if (! self::isActive()) {
            Setting::put(self::EXISTING_CURSOR, null);

            return 0;
        }

        $deadline = microtime(true) + $seconds;
        $cursor = (int) $cursor;
        $compressed = 0;
        $batch = self::existingQuery($cursor)->with(['model' => fn ($query) => $query->withTrashed()])->limit(self::BATCH)->get();

        foreach ($batch as $media) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                $compressed += self::compressMedia($media) ? 1 : 0;
            } catch (Throwable $exception) {
                report($exception);
            }

            $cursor = $media->getKey();
        }

        $done = $batch->isEmpty() || ($batch->count() < self::BATCH && $cursor === $batch->last()->getKey());
        Setting::put(self::EXISTING_CURSOR, $done ? null : $cursor);

        return $compressed;
    }

    /** @return Builder<Media> photo originals after the media id $cursor */
    private static function existingQuery(int $cursor): Builder
    {
        return Media::query()
            ->where('model_type', (new Photo)->getMorphClass())
            ->where('collection_name', Photo::COLLECTION)
            ->where('id', '>', $cursor)
            ->orderBy('id');
    }

    /** Compresses one stored original, if the current settings would change it. */
    private static function compressMedia(Media $media): bool
    {
        $photo = $media->model;

        if (! $photo instanceof Photo || ! self::wouldChange($photo->width, $photo->height, $media->getCustomProperty(self::PROPERTY))) {
            return false;
        }

        $disk = Storage::disk($media->disk);
        $stored = $media->getPathRelativeToRoot();
        $temporary = TemporaryDirectory::create();

        try {
            $path = $temporary->path('original.img');
            file_put_contents($path, $disk->get($stored));

            if (! self::compress($path)) {
                return false;
            }

            $disk->put($stored, (string) file_get_contents($path));

            $media->size = (int) filesize($path);
            $media->setCustomProperty(self::PROPERTY, self::settings());
            $media->save();

            [$width, $height] = self::shownSize($path, $photo->exif['Orientation'] ?? 1);
            $photo->forceFill(['width' => $width, 'height' => $height])->saveQuietly();

            return true;
        } finally {
            $temporary->delete();
        }
    }

    /**
     * Compresses the file at $path in place with the current settings.
     *
     * @return bool whether the file was replaced by a smaller one
     */
    public static function compress(string $path): bool
    {
        if (! self::isActive()) {
            return false;
        }

        $info = @getimagesize($path);
        $format = self::FORMATS[$info['mime'] ?? ''] ?? null;

        if ($format === null || ! in_array($info['mime'], PhotoUploads::processableTypes(), true)) {
            return false;
        }

        ['max' => $max, 'quality' => $quality] = self::settings();
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $scale = $max !== null && max($width, $height) > $max ? $max / max($width, $height) : 1.0;

        // Lossless PNG only gets smaller by scaling down.
        if ($scale >= 1.0 && ($quality === null || $format === 'png')) {
            return false;
        }

        $original = (string) file_get_contents($path);

        if ($format === 'webp' && self::isAnimatedWebp($original)) {
            return false;
        }

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $quality ??= self::RESIZE_QUALITY;

        $compressed = config('media-library.image_driver') === 'imagick'
            ? self::withImagick($path, $newWidth, $newHeight, $quality, $format)
            : self::withGd($original, $width, $height, $newWidth, $newHeight, $quality, $format);

        if ($compressed === null || $compressed === '' || strlen($compressed) >= strlen($original)) {
            return false;
        }

        $temporary = $path.'.compressed';
        file_put_contents($temporary, $compressed);

        return rename($temporary, $path);
    }

    private static function withImagick(string $path, int $width, int $height, int $quality, string $format): ?string
    {
        try {
            $image = new Imagick($path);

            if ($image->getImageWidth() !== $width || $image->getImageHeight() !== $height) {
                // resizeImage keeps the profiles (thumbnailImage would strip them).
                $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
            }

            if ($format !== 'png') {
                $image->setImageCompressionQuality($quality);
            }

            $blob = $image->getImageBlob();
            $image->clear();

            return $blob;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private static function withGd(string $original, int $width, int $height, int $newWidth, int $newHeight, int $quality, string $format): ?string
    {
        PhotoUploads::ensureMemoryFor($width + $newWidth, max($height, $newHeight));

        $image = @imagecreatefromstring($original);

        if ($image === false) {
            return null;
        }

        if ($format !== 'jpeg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        if ($newWidth !== $width || $newHeight !== $height) {
            // imagecopyresampled works with every GD build (imagescale's bicubic mode fails on some).
            $scaled = imagecreatetruecolor($newWidth, $newHeight);

            if ($format !== 'jpeg') {
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
            }

            if (! imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
                return null;
            }

            $image = $scaled;
        }

        ob_start();
        $written = match ($format) {
            'jpeg' => imagejpeg($image, null, $quality),
            'webp' => imagewebp($image, null, $quality),
            'png' => imagepng($image, null, 9),
        };
        $encoded = (string) ob_get_clean();

        if (! $written || $encoded === '') {
            return null;
        }

        return match ($format) {
            'jpeg' => self::withJpegMetadata($encoded, $original),
            'webp' => self::withWebpMetadata($encoded, $original, $newWidth, $newHeight),
            'png' => self::withPngMetadata($encoded, $original),
        };
    }

    /** $encoded with the metadata segments of $original right after its start, in place of GD's JFIF header. */
    private static function withJpegMetadata(string $encoded, string $original): string
    {
        $metadata = '';
        $position = 2;

        while ($position + 4 <= strlen($original) && $original[$position] === "\xFF") {
            $marker = $original[$position + 1];

            // Start of scan or end: no more headers.
            if ($marker === "\xDA" || $marker === "\xD9") {
                break;
            }

            $length = unpack('n', substr($original, $position + 2, 2))[1];

            if (in_array($marker, self::JPEG_METADATA_MARKERS, true)) {
                $metadata .= substr($original, $position, 2 + $length);
            }

            $position += 2 + $length;
        }

        if ($metadata === '') {
            return $encoded;
        }

        $rest = substr($encoded, 2);

        // GD starts with a JFIF segment (APP0); EXIF takes its place.
        if (str_starts_with($rest, "\xFF\xE0")) {
            $rest = substr($rest, 2 + unpack('n', substr($rest, 2, 2))[1]);
        }

        return "\xFF\xD8".$metadata.$rest;
    }

    /** $encoded with the metadata chunks of $original after its header, before the image data. */
    private static function withPngMetadata(string $encoded, string $original): string
    {
        $metadata = '';

        foreach (self::chunks($original, 8, 'N') as [$type, $chunk]) {
            if (in_array($type, self::PNG_METADATA_CHUNKS, true)) {
                $metadata .= $chunk;
            }
        }

        if ($metadata === '') {
            return $encoded;
        }

        // Signature (8 bytes) and IHDR (25 bytes) come first.
        return substr($encoded, 0, 33).$metadata.substr($encoded, 33);
    }

    /** $encoded in the extended WebP layout, so it can carry the metadata chunks of $original. */
    private static function withWebpMetadata(string $encoded, string $original, int $width, int $height): string
    {
        $metadata = [];
        $flags = 0;

        foreach (self::chunks($original, 12, 'V') as [$type, $chunk]) {
            if (isset(self::WEBP_METADATA_CHUNKS[$type])) {
                $metadata[$type] = $chunk;
                $flags |= self::WEBP_METADATA_CHUNKS[$type];
            }
        }

        if ($metadata === []) {
            return $encoded;
        }

        $image = '';

        foreach (self::chunks($encoded, 12, 'V') as [$type, $chunk]) {
            if ($type === 'VP8X') {
                // Keeps the alpha flag GD set.
                $flags |= ord($chunk[8]) & 0x10;
            } elseif (! isset(self::WEBP_METADATA_CHUNKS[$type])) {
                $image .= $chunk;
            }
        }

        if (str_contains($image, 'VP8L')) {
            // Lossless WebP may carry transparency in the image data itself.
            $flags |= 0x10;
        }

        $vp8x = 'VP8X'.pack('V', 10).chr($flags)."\0\0\0"
            .substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);

        $body = 'WEBP'.$vp8x.($metadata['ICCP'] ?? '').$image.($metadata['EXIF'] ?? '').($metadata['XMP '] ?? '');

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    private static function isAnimatedWebp(string $bytes): bool
    {
        foreach (self::chunks($bytes, 12, 'V') as [$type]) {
            if ($type === 'ANIM') {
                return true;
            }
        }

        return false;
    }

    /**
     * PNG (big-endian, with checksum) or RIFF (little-endian, padded) chunks.
     *
     * @return list<array{0: string, 1: string}> type and the whole chunk
     */
    private static function chunks(string $bytes, int $start, string $endian): array
    {
        $chunks = [];
        $size = strlen($bytes);
        $isPng = $endian === 'N';

        for ($position = $start; $position + 8 <= $size;) {
            $length = unpack($endian, substr($bytes, $position + ($isPng ? 0 : 4), 4))[1];
            $type = substr($bytes, $position + ($isPng ? 4 : 0), 4);
            $total = 8 + $length + ($isPng ? 4 : $length % 2);

            if ($position + $total > $size) {
                break;
            }

            $chunks[] = [$type, substr($bytes, $position, $total)];
            $position += $total;
        }

        return $chunks;
    }
}
