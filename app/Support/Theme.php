<?php

namespace App\Support;

use App\Extensions\ThemeManager;
use App\Models\Page;
use Illuminate\Support\Collection;

/**
 * Short helpers for Blade views and blocks to read the active theme's
 * settings, e.g. Theme::option('landing_heading'), Theme::image('landing_image'),
 * Theme::pages('footer_pages').
 */
class Theme
{
    public static function option(string $key, mixed $default = null): mixed
    {
        $value = app(ThemeManager::class)->option($key, $default);

        return $value === null || $value === '' ? $default : $value;
    }

    public static function enabled(string $key): bool
    {
        return (bool) self::option($key, false);
    }

    public static function image(string $key): ?string
    {
        return app(ThemeManager::class)->imageUrl($key);
    }

    /** @return Collection<int, Page> pages chosen in a "pages" field, visible to the current visitor */
    public static function pages(string $key): Collection
    {
        return Page::linkable((array) self::option($key, []), auth()->user());
    }
}
