<?php

namespace App\Support;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Actions\PerformConversionAction;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Throwable;

/**
 * Makes the `small` size for photos uploaded before it existed, a few at a
 * time in the background jobs (BackgroundJobs::tasks()). It is made from the
 * photo's `thumb` (720 px), not from the original, so each photo takes a few
 * milliseconds. Until then the site simply shows `thumb` (App\Support\Srcset).
 */
class SmallThumbnails
{
    /** Photos looked at per pass. */
    public const BATCH = 100;

    /** Seconds one pass may spend. */
    public const SECONDS = 15;

    /** @return int how many photos got their `small` size */
    public static function makeMissing(int $seconds = self::SECONDS): int
    {
        $deadline = microtime(true) + $seconds;
        $made = 0;

        $waiting = Media::query()
            ->where('model_type', (new Photo)->getMorphClass())
            ->where('collection_name', Photo::COLLECTION)
            ->where('generated_conversions', 'like', '%"thumb":true%')
            ->where('generated_conversions', 'not like', '%"small":true%')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($waiting as $media) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                self::make($media);
                $made++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $made;
    }

    private static function make(Media $media): void
    {
        $temporary = TemporaryDirectory::create();

        try {
            $thumb = $temporary->path('thumb.webp');
            file_put_contents($thumb, Storage::disk($media->conversions_disk)->get($media->getPathRelativeToRoot('thumb')));

            // The media library's own step, with the thumb in place of the original.
            (new PerformConversionAction)->execute(ConversionCollection::createForMedia($media)->getByName('small'), $media, $thumb);
        } finally {
            $temporary->delete();
        }
    }
}
