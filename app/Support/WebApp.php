<?php

namespace App\Support;

use App\Extensions\ThemeManager;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Throwable;

/**
 * The site as an installable app (PWA): the web app manifest, the app icons,
 * the browser's theme colour and the service worker (`public/sw.js`).
 *
 * The service worker only keeps the offline page and static files (built
 * assets, theme files, branding); pages and photos always come from the
 * server, so nothing private is stored on the device.
 */
class WebApp
{
    /** Icon sizes in px: the home screen icon on iPhone / iPad, and the two sizes the manifest lists. */
    public const ICON_SIZES = [180, 192, 512];

    /** Where generated icon sizes of an uploaded app icon are kept (on SiteSettings::DISK). */
    public const ICON_DIRECTORY = SiteSettings::DIRECTORY.'/app-icons';

    /** Favicon formats that can be turned into an app icon. SVG is left out: it could carry scripts or remote files. */
    private const FAVICON_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public static function enabled(): bool
    {
        return (bool) Setting::get(SiteSettings::WEB_APP, true);
    }

    /**
     * The web app manifest, in the site's default language.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $root = rtrim(url('/'), '/').'/';
        $colors = self::themeColors();
        $uploaded = self::iconSource() !== null;

        $icons = [];
        foreach ([192, 512] as $size) {
            $icon = ['src' => self::iconUrl($size), 'sizes' => "{$size}x{$size}", 'type' => 'image/png'];
            $icons[] = $icon + ['purpose' => 'any'];

            // The built-in icon leaves room around the mark, so it may be cut to any shape.
            if (! $uploaded) {
                $icons[] = $icon + ['purpose' => 'maskable'];
            }
        }

        return [
            'id' => $root,
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'lang' => str_replace('_', '-', (string) config('gallery.default_locale', config('app.locale'))),
            'start_url' => $root,
            'scope' => $root,
            'display' => 'standalone',
            'background_color' => $colors['background'],
            'theme_color' => $colors['light'],
            'icons' => $icons,
        ];
    }

    /**
     * The browser bar colour (the header background of the active theme) in
     * light and dark mode, and the splash screen background (the page background).
     *
     * @return array{light: string, dark: string, background: string}
     */
    public static function themeColors(): array
    {
        [$light, $dark] = app(ThemeManager::class)->resolvedVariables();

        $pick = fn (array $vars, array $tokens, string $fallback): string => collect($tokens)
            ->map(fn (string $token) => AdminTheme::hex((string) ($vars[$token] ?? '')))
            ->filter()
            ->first() ?? $fallback;

        $lightHeader = $pick($light, ['--og-header-canvas', '--og-canvas'], '#FFFFFF');

        return [
            'light' => $lightHeader,
            'dark' => $pick($dark, ['--og-header-canvas', '--og-canvas'], $lightHeader),
            'background' => $pick($light, ['--og-canvas'], '#FFFFFF'),
        ];
    }

    /** URL of the app icon in one of ICON_SIZES: made from an upload, else the built-in mark. */
    public static function iconUrl(int $size): string
    {
        $source = self::iconSource();

        return $source
            ? route('web-app.icon', ['size' => $size, 'v' => substr(md5($source), 0, 8)])
            : asset("web-app/icon-{$size}.png");
    }

    /** The built-in mark in one of ICON_SIZES, as a file in `public`. */
    public static function builtInIconPath(int $size): string
    {
        return public_path("web-app/icon-{$size}.png");
    }

    /** The upload the app icon is made from: the app icon, else a favicon in a usable format. */
    public static function iconSource(): ?string
    {
        if ($appIcon = SiteSettings::assetPath('app-icon')) {
            return $appIcon;
        }

        $favicon = SiteSettings::assetPath('favicon');
        $extension = strtolower(pathinfo((string) $favicon, PATHINFO_EXTENSION));

        return $favicon && in_array($extension, self::FAVICON_EXTENSIONS, true) ? $favicon : null;
    }

    /**
     * Path (on SiteSettings::DISK) of the icon in the given size, made on first
     * use as a square PNG: an app icon is cropped, a favicon is fitted on white
     * (iPhones show transparent areas black). Null without a source, for
     * unknown sizes or if the image cannot be read.
     */
    public static function iconPath(int $size): ?string
    {
        $source = self::iconSource();

        if (! $source || ! in_array($size, self::ICON_SIZES, true)) {
            return null;
        }

        $disk = Storage::disk(SiteSettings::DISK);
        $path = self::ICON_DIRECTORY.'/'.substr(md5($source), 0, 16)."-{$size}.png";

        if (! $disk->exists($path)) {
            $disk->makeDirectory(self::ICON_DIRECTORY);

            try {
                $image = Image::useImageDriver(config('media-library.image_driver', 'gd'))
                    ->loadFile($disk->path($source));

                if ($source === SiteSettings::assetPath('app-icon')) {
                    $image->fit(Fit::Crop, $size, $size);
                } else {
                    $image->fit(Fit::FillMax, $size, $size, backgroundColor: '#ffffff')->background('#ffffff');
                }

                $image->format('png')->save($disk->path($path));
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }
        }

        return $path;
    }

    /** Removes the generated icon sizes (after the app icon or favicon was replaced or removed). */
    public static function forgetIcons(): void
    {
        Storage::disk(SiteSettings::DISK)->deleteDirectory(self::ICON_DIRECTORY);
    }

    /**
     * URL the service worker is registered with. Its query carries the offline
     * page and a version that changes with the build, the theme, the branding
     * and the language, so browsers fetch a fresh offline page after such changes.
     */
    public static function serviceWorkerUrl(): string
    {
        return asset('sw.js').'?'.http_build_query([
            'v' => self::version(),
            'offline' => route('web-app.offline'),
        ]);
    }

    private static function version(): string
    {
        $themes = app(ThemeManager::class);
        $build = public_path('build/manifest.json');

        return substr(md5(serialize([
            is_file($build) ? filemtime($build) : null,
            config('app.name'),
            app()->getLocale(),
            $themes->active()?->slug,
            $themes->options(),
            $themes->stylesheets(),
            SiteSettings::faviconUrl(),
            SiteSettings::assetUrl('logo'),
            SiteSettings::assetUrl('logo-dark'),
        ])), 0, 12);
    }
}
