<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Photo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * New titles for several photos from a pattern ("Rename" bulk action in the
 * media library), e.g. "{album} {taken} {n}" → "Summer camp 2026-07-14 001".
 * Only the title changes; the stored file keeps its name. Photos are counted
 * in the order they were taken (upload time when unknown).
 */
class PhotoRenaming
{
    /** Longest title the pattern may produce (the column holds 255). */
    public const MAX_LENGTH = 255;

    /**
     * The placeholders a pattern may use, with what each stands for.
     *
     * @return array<string, string>
     */
    public static function tokens(): array
    {
        return [
            '{n}' => __('Running number'),
            '{title}' => __('Current title'),
            '{original}' => __('Uploaded file name (without extension)'),
            '{album}' => __('Album'),
            '{uploader}' => __('Uploaded by'),
            '{taken}' => __('Date taken (upload date when unknown)'),
            '{uploaded}' => __('Upload date'),
        ];
    }

    /** What is wrong with the pattern, or null. */
    public static function problemWith(string $pattern): ?string
    {
        if (trim($pattern) === '') {
            return __('Enter a pattern.');
        }

        preg_match_all('/\{[^{}]*\}/', $pattern, $matches);
        $unknown = array_diff($matches[0], array_keys(self::tokens()));

        return $unknown === [] ? null : __('":token" is not a placeholder you can use.', ['token' => reset($unknown)]);
    }

    /**
     * The new title for each photo, in counting order.
     *
     * @param  Collection<int, Photo>  $photos
     * @return array<int, string> titles by photo id
     */
    public static function titles(Collection $photos, string $pattern, int $start = 1, int $digits = 1): array
    {
        $photos->loadMissing(['media', 'albums', 'uploader']);
        $titles = [];
        $number = $start;

        foreach (self::inCountingOrder($photos) as $photo) {
            $titles[$photo->id] = self::titleFor($photo, $pattern, str_pad((string) $number, max(1, $digits), '0', STR_PAD_LEFT));
            $number++;
        }

        return $titles;
    }

    /**
     * Oldest first by date taken (upload time when unknown), then by id.
     *
     * @param  Collection<int, Photo>  $photos
     * @return Collection<int, Photo>
     */
    private static function inCountingOrder(Collection $photos): Collection
    {
        return $photos->sortBy([
            fn (Photo $a, Photo $b) => ($a->taken_at ?? $a->created_at) <=> ($b->taken_at ?? $b->created_at),
            fn (Photo $a, Photo $b) => $a->id <=> $b->id,
        ])->values();
    }

    private static function titleFor(Photo $photo, string $pattern, string $number): string
    {
        $original = $photo->original()?->file_name;

        $title = strtr($pattern, [
            '{n}' => $number,
            '{title}' => (string) $photo->title,
            '{original}' => $original !== null ? pathinfo($original, PATHINFO_FILENAME) : '',
            '{album}' => self::albumOf($photo)?->title ?? '',
            '{uploader}' => (string) $photo->uploader?->name,
            '{taken}' => ($photo->taken_at ?? $photo->created_at)?->format('Y-m-d') ?? '',
            '{uploaded}' => $photo->created_at?->format('Y-m-d') ?? '',
        ]);

        // Empty placeholders leave no double spaces or dangling separators.
        $title = trim((string) preg_replace('/\s+/u', ' ', $title), " \t-_.,");

        return Str::limit($title, self::MAX_LENGTH, '');
    }

    /** The album named in {album}: its first own album, the uploader's "Unsorted uploads" only if it is in no other. */
    private static function albumOf(Photo $photo): ?Album
    {
        $albums = $photo->activeAlbums()->sortBy('id');

        return $albums->first(fn (Album $album) => ! $album->isSystem()) ?? $albums->first();
    }
}
