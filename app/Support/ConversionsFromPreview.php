<?php

namespace App\Support;

use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;

/**
 * The media library's image generator (config/media-library.php
 * `image_generators`), with one change: the smaller conversions (thumb,
 * small) start from the preview made a moment earlier in the same run
 * instead of decoding the full-size original again. Decoding a camera photo
 * is most of the work, so an upload takes about a third less time, and an
 * extra size costs milliseconds. Photo registers `preview` first; without a
 * preview in the run (e.g. regenerating only `thumb`) the original is used.
 */
class ConversionsFromPreview extends Image
{
    public const SOURCE = 'preview';

    /** Conversions made from the preview when it is there. */
    public const FROM_PREVIEW = ['thumb', 'small'];

    public function convert(string $path, ?Conversion $conversion = null): string
    {
        if ($conversion === null || ! in_array($conversion->getName(), self::FROM_PREVIEW, true)) {
            return $path;
        }

        // Finished conversions wait in the run's temporary folder as "{name}-{conversion}.webp".
        $previews = glob(dirname($path).'/*-'.self::SOURCE.'.webp') ?: [];

        return count($previews) === 1 ? $previews[0] : $path;
    }
}
