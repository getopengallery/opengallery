<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Which releases the update check reports (Admin → Settings → Updates, see
 * App\Support\UpdateCheck).
 */
enum UpdateChannel: string implements HasDescription, HasLabel
{
    /** Finished releases only. */
    case Stable = 'stable';
    /** Finished releases and prereleases, whichever is newest. */
    case Dev = 'dev';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stable => __('Stable'),
            self::Dev => __('Development'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Stable => __('Finished versions for everyday use. Recommended.'),
            self::Dev => __('Also tells you about test versions. They can contain errors, so use them only on a gallery you can restore.'),
        };
    }
}
