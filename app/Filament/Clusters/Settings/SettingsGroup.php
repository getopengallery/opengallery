<?php

namespace App\Filament\Clusters\Settings;

use Filament\Support\Contracts\HasLabel;

/**
 * The headings of the menu inside Admin → Settings. Groups appear in the order
 * of their first page's `$navigationSort`.
 */
enum SettingsGroup implements HasLabel
{
    case Site;
    case People;
    case Content;
    case System;

    public function getLabel(): string
    {
        return match ($this) {
            self::Site => __('Site'),
            self::People => __('People'),
            self::Content => __('Content'),
            self::System => __('System'),
        };
    }
}
