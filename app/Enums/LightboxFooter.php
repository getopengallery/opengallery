<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What the lightbox shows below the photo (Settings → Photos). Built per
 * photo by App\Support\PhotoGridItems as the item's `footer`.
 */
enum LightboxFooter: string implements HasLabel
{
    case DescriptionAndTaken = 'description_taken';
    case Title = 'title';
    case FileName = 'file_name';
    case Description = 'description';
    case Taken = 'taken';
    case License = 'license';
    case Camera = 'camera';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::DescriptionAndTaken => __('Description and date taken'),
            self::Title => __('Image name'),
            self::FileName => __('File name'),
            self::Description => __('Description'),
            self::Taken => __('Date taken'),
            self::License => __('License'),
            self::Camera => __('Camera and settings'),
            self::None => __('Nothing'),
        };
    }
}
