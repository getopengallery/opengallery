<?php

namespace App\Support;

use DateTimeInterface;

/**
 * The one way dates and times are shown everywhere (site, admin, e-mails,
 * activity log): European day.month.year with a 24-hour clock, in every
 * language. Filament's tables, infolists and date pickers take these as
 * their defaults (AppServiceProvider); views and PHP use the helpers.
 */
final class DateFormat
{
    /** 20.11.2026 */
    public const DATE = 'd.m.Y';

    /** 14:30 */
    public const TIME = 'H:i';

    /** 20.11.2026 14:30 */
    public const DATE_TIME = 'd.m.Y H:i';

    /** 20.11.2026 14:30:05, where the exact second matters (activity log). */
    public const DATE_TIME_SECONDS = 'd.m.Y H:i:s';

    /** The same formats for Carbon::isoFormat() (Filament's "iso" variants). */
    public const ISO_DATE = 'DD.MM.YYYY';

    public const ISO_TIME = 'HH:mm';

    public const ISO_DATE_TIME = 'DD.MM.YYYY HH:mm';

    public static function date(?DateTimeInterface $value): ?string
    {
        return $value?->format(self::DATE);
    }

    public static function dateTime(?DateTimeInterface $value): ?string
    {
        return $value?->format(self::DATE_TIME);
    }

    public static function dateTimeWithSeconds(?DateTimeInterface $value): ?string
    {
        return $value?->format(self::DATE_TIME_SECONDS);
    }
}
