<?php

namespace App\Actions;

use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use App\Support\Licensing;
use App\Support\MediaSizes;
use App\Support\PhotoCompression;
use App\Support\PhotoUploads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates a Photo in $album from a local file: extracts EXIF, compresses
 * the file when that is on (PhotoCompression), stores it via media library (which generates thumb/preview conversions)
 * and sets the album cover if it has none yet.
 */
class AddPhotoToAlbum
{
    /** EXIF keys worth keeping; the full blob is large and may contain junk. */
    private const EXIF_KEYS = [
        'Make', 'Model', 'LensModel', 'ExposureTime', 'FNumber', 'ISOSpeedRatings',
        'FocalLength', 'Flash', 'Orientation', 'DateTimeOriginal',
        'GPSLatitude', 'GPSLatitudeRef', 'GPSLongitude', 'GPSLongitudeRef',
    ];

    public function handle(Album $album, string $path, string $originalName, ?User $uploader = null): Photo
    {
        $exif = $this->readExif($path);

        // Stored turned (EXIF orientation 6 or 8, e.g. phones held upright): width and height as shown.
        [$width, $height] = PhotoCompression::shownSize($path, $exif['Orientation'] ?? 1);

        // Refuse images GD cannot decode in this process before anything is stored.
        PhotoUploads::ensureMemoryFor((int) $width, (int) $height);

        // Replaces the uploaded file with a smaller one when compression is on; the metadata stays in it.
        $compression = PhotoCompression::isActive() ? PhotoCompression::settings() : null;
        $uploadedBytes = (int) filesize($path);

        if ($compression && PhotoCompression::compress($path)) {
            [$width, $height] = PhotoCompression::shownSize($path, $exif['Orientation'] ?? 1);
        }

        // In the trash until it is ready, so no grid shows it without its images.
        $photo = new Photo([
            'uploader_id' => $uploader?->id,
            'license_id' => Licensing::forNewPhoto(),
            'title' => pathinfo($originalName, PATHINFO_FILENAME),
            'taken_at' => $this->takenAt($exif),
            'width' => $width,
            'height' => $height,
            'exif' => $exif ?: null,
        ]);
        $photo->forceFill([$photo->getDeletedAtColumn() => now()])->save();

        // A PHP timeout or out-of-memory error skips the catch below, but must not leave half a photo either.
        $unfinished = $photo;
        register_shutdown_function(function () use (&$unfinished) {
            if ($unfinished) {
                rescue(fn () => $unfinished->forceDelete());
            }
        });

        try {
            // Outside any transaction: making the conversions takes seconds, and on SQLite
            // an open write transaction would make every other request wait for them.
            $photo->addMedia($path)
                ->preservingOriginal()
                ->usingFileName($this->safeFileName($originalName))
                ->withCustomProperties($compression ? [PhotoCompression::PROPERTY => $compression, MediaSizes::ORIGINAL => $uploadedBytes] : [])
                ->toMediaCollection(Photo::COLLECTION);

            DB::transaction(function () use ($album, $photo) {
                // Writes first: on SQLite a transaction that reads first cannot wait for the write lock.
                $photo->restoreQuietly();

                // Also makes it the album cover if the album has none yet.
                $album->addPhotos($photo);
            });
        } catch (Throwable $e) {
            $unfinished = null;
            rescue(fn () => $photo->forceDelete());

            throw $e;
        }

        $unfinished = null;
        @unlink($path);

        return $photo;
    }

    /** @return array<string, mixed> */
    private function readExif(string $path): array
    {
        if (! function_exists('exif_read_data')) {
            return [];
        }

        try {
            $data = @exif_read_data($path) ?: [];
        } catch (Throwable) {
            return [];
        }

        $kept = array_intersect_key($data, array_flip(self::EXIF_KEYS));

        // Keep JSON-safe scalars/arrays only.
        return json_decode(json_encode($kept, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]', true) ?? [];
    }

    /** @param array<string, mixed> $exif */
    private function takenAt(array $exif): ?CarbonImmutable
    {
        if (empty($exif['DateTimeOriginal'])) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeFileName(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = str(pathinfo($name, PATHINFO_FILENAME))->slug()->limit(100, '')->value() ?: 'photo';

        return $ext ? "{$base}.{$ext}" : $base;
    }
}
