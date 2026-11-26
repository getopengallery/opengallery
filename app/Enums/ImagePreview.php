<?php

namespace App\Enums;

/** How App\Filament\Forms\Components\ImageUpload shows its image. */
enum ImagePreview: string
{
    /** Whole image on a light checkerboard, where a dark logo stays visible. */
    case Logo = 'logo';

    /** Whole image on a dark checkerboard, where a white logo stays visible. */
    case DarkLogo = 'dark-logo';

    /** Small square on a checkerboard, optionally next to a sample of where it is used. */
    case Icon = 'icon';

    /** Fills a wide frame, for background images. */
    case Wide = 'wide';

    /** A square box with the picture cropped to a circle and some space around it, for profile pictures. */
    case Square = 'square';

    /** CSS classes of the preview area (filament.partials.theme-styles). */
    public function stageClasses(): string
    {
        return match ($this) {
            self::Logo => 'is-contain is-on-light og-iu-check',
            self::DarkLogo => 'is-contain is-on-dark og-iu-check',
            self::Icon => 'is-icon is-on-theme og-iu-check',
            self::Wide => 'is-wide',
            self::Square => 'is-square',
        };
    }
}
