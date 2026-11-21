<?php

namespace App\Support;

/**
 * How the browser should load the next photo of a page. Blocks ask once per
 * photo, in the order the page shows them (album tiles, photo grids, photos
 * in page content), so the first photos of a page, which are on screen
 * straight away, load at once (the very first with high priority, as it is
 * usually the largest element) and the rest only when scrolled to.
 */
class ImageLoading
{
    /** Photos per page that load straight away. */
    public const EAGER = 6;

    private const COUNTER = 'image-loading.next';

    /**
     * The <img> attributes for the next photo: print them as
     * loading="…" fetchpriority="…" decoding="…".
     *
     * @return array{loading: 'eager'|'lazy', fetchpriority: 'high'|'auto', decoding: 'async'|'auto'}
     */
    public static function next(): array
    {
        $position = RequestMemo::instance()->next(self::COUNTER);
        $eager = $position < self::EAGER;

        return [
            'loading' => $eager ? 'eager' : 'lazy',
            'fetchpriority' => $position === 0 ? 'high' : 'auto',
            // Decoding off the main thread suits photos further down; on screen it could delay the first paint.
            'decoding' => $eager ? 'auto' : 'async',
        ];
    }
}
