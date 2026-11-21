<?php

namespace App\Support;

use App\Models\Photo;

/**
 * The `srcset` of a photo: its conversions with their real widths, so the
 * browser picks the smallest file that is sharp enough for the screen
 * (`small` on phones, `thumb` on large or sharp screens). Only conversions
 * that exist are listed; with fewer than two there is nothing to choose.
 */
class Srcset
{
    /** @param  array<string, string|null>  $urls  conversion name => address (null: not made yet) */
    public static function for(Photo $photo, array $urls): ?string
    {
        $candidates = collect($urls)
            ->filter()
            ->map(fn (string $url, string $conversion) => $url.' '.self::width($photo, $conversion).'w')
            ->values();

        return $candidates->count() > 1 ? $candidates->implode(', ') : null;
    }

    /** Width in pixels of a conversion (Fit::Max: longest side at most the size, never enlarged). */
    public static function width(Photo $photo, string $conversion): int
    {
        $size = Photo::CONVERSION_SIZES[$conversion];

        if (! $photo->width || ! $photo->height) {
            return $size;
        }

        return (int) round($photo->width * min(1, $size / max($photo->width, $photo->height)));
    }
}
