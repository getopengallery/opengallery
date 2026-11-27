<?php

namespace App\Support;

use App\Models\Photo;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * File sizes for the statistics page, kept in each photo's media
 * custom properties because the media table only knows the original's size:
 *
 *  - ORIGINAL: the size of the uploaded file before compression replaced it
 *    (only for files compressed while sizes were recorded);
 *  - CONVERSIONS: conversion name => bytes of each generated WebP, written
 *    whenever the media library finishes a conversion (recordConversion()).
 *
 * Photos stored before sizes were recorded are filled in a few hundred at a
 * time by the background jobs (fillMissing()).
 */
class MediaSizes
{
    /** Custom property: bytes of the original before compression. */
    public const ORIGINAL = 'original_size';

    /** Custom property: conversion name => bytes. */
    public const CONVERSIONS = 'conversion_sizes';

    /** Setting: the last media id fillMissing() looked at; null once all are done. */
    public const CURSOR = 'statistics.media_sizes_cursor';

    /** Setting: true once fillMissing() has looked at every photo. */
    public const DONE = 'statistics.media_sizes_done';

    /** Photos looked at per pass. */
    public const BATCH = 300;

    /** Seconds one pass may spend. */
    public const SECONDS = 10;

    /** Stores the size of a conversion the media library just wrote. */
    public static function recordConversion(Media $media, string $conversion): void
    {
        if ($media->model_type !== (new Photo)->getMorphClass()) {
            return;
        }

        try {
            $bytes = Storage::disk($media->conversions_disk)->size($media->getPathRelativeToRoot($conversion));
        } catch (Throwable) {
            return;
        }

        $sizes = (array) $media->getCustomProperty(self::CONVERSIONS, []);
        $sizes[$conversion] = (int) $bytes;
        $media->setCustomProperty(self::CONVERSIONS, $sizes);
        $media->save();
    }

    /**
     * Records the original's size before a compression replaces it, unless
     * an earlier compression already did (the first size is the real original).
     */
    public static function rememberOriginal(Media $media, int $bytes): void
    {
        if ($media->getCustomProperty(self::ORIGINAL) === null) {
            $media->setCustomProperty(self::ORIGINAL, $bytes);
        }
    }

    /** Whether older photos still lack their conversion sizes. */
    public static function filling(): bool
    {
        return ! Setting::get(self::DONE, false) && self::query(0)->exists();
    }

    /**
     * Reads the conversion sizes of photos stored before they were recorded.
     *
     * @return int how many photos got their sizes
     */
    public static function fillMissing(int $seconds = self::SECONDS): int
    {
        if (Setting::get(self::DONE, false)) {
            return 0;
        }

        $deadline = microtime(true) + $seconds;
        $cursor = (int) Setting::get(self::CURSOR, 0);
        $filled = 0;
        $batch = self::query($cursor)->limit(self::BATCH)->get();

        foreach ($batch as $media) {
            if (microtime(true) > $deadline) {
                break;
            }

            $cursor = $media->getKey();

            try {
                $filled += self::fill($media) ? 1 : 0;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($batch->count() < self::BATCH && ($batch->isEmpty() || $cursor === $batch->last()->getKey())) {
            Setting::put(self::DONE, true);
            Setting::put(self::CURSOR, null);
        } else {
            Setting::put(self::CURSOR, $cursor);
        }

        return $filled;
    }

    private static function fill(Media $media): bool
    {
        $disk = Storage::disk($media->conversions_disk);
        $sizes = [];

        foreach (array_keys(array_filter((array) $media->generated_conversions)) as $conversion) {
            $path = $media->getPathRelativeToRoot($conversion);

            if ($disk->exists($path)) {
                $sizes[$conversion] = (int) $disk->size($path);
            }
        }

        if ($sizes === []) {
            return false;
        }

        $media->setCustomProperty(self::CONVERSIONS, $sizes);
        $media->saveQuietly();

        return true;
    }

    /** @return Builder<Media> photo originals without recorded sizes after the media id $cursor */
    private static function query(int $cursor): Builder
    {
        return Media::query()
            ->where('model_type', (new Photo)->getMorphClass())
            ->where('collection_name', Photo::COLLECTION)
            ->where('custom_properties', 'not like', '%"'.self::CONVERSIONS.'"%')
            ->where('id', '>', $cursor)
            ->orderBy('id');
    }
}
