<?php

namespace App\Support;

use App\Models\Photo;

/**
 * The camera details of a photo's stored EXIF data, readable for people:
 * shown under "Camera" in the admin (PhotoInfolist) and in the lightbox's
 * information panel. Never includes location data.
 */
class PhotoExif
{
    /** EXIF keys shown as camera details, with readable labels (English, translated when shown). */
    public const LABELS = [
        'Make' => 'Make',
        'Model' => 'Camera',
        'LensModel' => 'Lens',
        'FocalLength' => 'Focal length',
        'FNumber' => 'Aperture',
        'ExposureTime' => 'Exposure',
        'ISOSpeedRatings' => 'ISO',
    ];

    /** @return list<array{label: string, value: string}> the camera details the photo has, labels translated */
    public static function details(Photo $photo): array
    {
        $exif = (array) $photo->exif;
        $details = [];

        foreach (self::LABELS as $key => $label) {
            $value = self::value($key, $exif[$key] ?? null);

            if (filled($value)) {
                $details[] = ['label' => __($label), 'value' => $value];
            }
        }

        return $details;
    }

    /** EXIF stores fractions like "1/250" or "28/10"; show them the way photographers read them. */
    public static function value(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return is_array($value) ? implode(', ', $value) : null;
        }

        $number = is_string($value) && preg_match('#^(\d+)/(\d+)$#', $value, $m) && (int) $m[2] !== 0
            ? (int) $m[1] / (int) $m[2]
            : (is_numeric($value) ? (float) $value : null);

        return match ($key) {
            'FNumber' => $number ? 'f/'.rtrim(rtrim(number_format($number, 1), '0'), '.') : (string) $value,
            'FocalLength' => $number ? round($number).' mm' : (string) $value,
            'ExposureTime' => $number ? ($number >= 1 ? rtrim(rtrim(number_format($number, 1), '0'), '.').' s' : '1/'.round(1 / $number).' s') : (string) $value,
            default => (string) $value,
        };
    }
}
