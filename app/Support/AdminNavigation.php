<?php

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Page changes inside the admin without a full reload (Filament's SPA mode,
 * Livewire's wire:navigate): only the page content is swapped, scripts and
 * styles stay loaded.
 *
 * Links that leave the admin (the site, photo files, downloads, login) must
 * still load as a whole page, because the site has its own styles and
 * scripts. Filament only accepts patterns of URLs to leave out, so these
 * are built from every route outside the admin, plugin routes included.
 */
class AdminNavigation
{
    /** Path prefixes that are never pages of their own. */
    private const IGNORED_PREFIXES = ['livewire', '_boost'];

    /**
     * URL patterns (Str::is) of everything outside the admin panel.
     *
     * @return list<string>
     */
    public static function fullPageUrls(string $panelPath): array
    {
        $patterns = [url('/'), url('/').'?*', url('/').'#*'];

        foreach (self::siteSegments($panelPath) as $segment) {
            $base = url($segment);
            array_push($patterns, $base, $base.'/*', $base.'?*', $base.'#*');
        }

        return $patterns;
    }

    /**
     * First path segments of the routes outside the admin, such as "albums"
     * or "media". A route that starts with a parameter is skipped, since its
     * pattern would also cover the admin.
     *
     * @return list<string>
     */
    private static function siteSegments(string $panelPath): array
    {
        $panelSegment = strtok(trim($panelPath, '/'), '/');

        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (Route $route): string => (string) strtok(trim($route->uri(), '/'), '/'))
            ->reject(fn (string $segment): bool => $segment === ''
                || str_contains($segment, '{')
                || $segment === $panelSegment
                || collect(self::IGNORED_PREFIXES)->contains(fn (string $prefix): bool => str_starts_with($segment, $prefix)))
            ->unique()
            ->values()
            ->all();
    }
}
