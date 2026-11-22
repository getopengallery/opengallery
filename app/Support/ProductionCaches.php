<?php

namespace App\Support;

use App\Support\Setup\Installation;
use BladeUI\Icons\Factory as IconsFactory;
use BladeUI\Icons\IconsManifest;
use Filament\Facades\Filament;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * Laravel's and Filament's production caches: configuration, routes,
 * events, and Filament's components and icons (bootstrap/cache). They make
 * every request, every photo included, a few milliseconds faster.
 *
 * Live sites (APP_ENV=production, GALLERY_OPTIMIZE not false) keep them
 * built without anyone running a command: KeepProductionCachesFresh builds
 * them after the response when they are missing, and drops and rebuilds
 * them when the code or `.env` changed since (fingerprint()). Enabling or
 * disabling a plugin rebuilds them too (outdated()), because plugins add
 * routes, configuration and admin pages. Compiled views are left to Blade,
 * which compiles each view on first use and keeps it.
 *
 * The configuration cache holds what the files say, never what the admin
 * stored: while it is built, AppServiceProvider skips the runtime settings
 * (SiteSettings::apply(), theme paths), see buildingConfigCache().
 */
class ProductionCaches
{
    /** Cache key: when the last build was started; set while builds fail, so they are retried at most hourly. */
    private const ATTEMPT = 'gallery.production-caches.attempt';

    private const RETRY_SECONDS = 3600;

    /** Set while build() writes the configuration cache. */
    private const BUILDING = 'GALLERY_BUILDING_CONFIG_CACHE';

    /** Built in fresh applications, so they always reflect the files and enabled plugins. */
    private const BUILD = ['config:cache', 'event:cache', 'route:cache'];

    private const CLEAR = ['config:clear', 'event:clear', 'route:clear'];

    /** Whether this installation keeps the caches built. */
    public static function wanted(): bool
    {
        return app()->isProduction() && (bool) config('gallery.optimize', true) && Installation::isInstalled();
    }

    public static function built(): bool
    {
        return app()->configurationIsCached();
    }

    /**
     * What to do on a request: drop caches that no longer match the code
     * ($fresh false) and build them where wanted and missing or dropped.
     *
     * @return array{clear: bool, build: bool}
     */
    public static function plan(bool $built, bool $fresh, bool $wanted): array
    {
        return [
            'clear' => $built && ! $fresh,
            'build' => $wanted && (! $built || ! $fresh),
        ];
    }

    /** Called for every request (KeepProductionCachesFresh); builds after the response. */
    public static function check(): void
    {
        $built = self::built();
        $fresh = ! $built || config('gallery.code_fingerprint') === self::fingerprint();
        $wanted = self::wanted();
        $plan = self::plan($built, $fresh, $wanted);

        // Read before clear(), which resets it. A panel loaded from Filament's cache skipped
        // discovery, so writing its list back would keep pages from an older version.
        $adminFromCache = Filament::getDefaultPanel()->hasCachedComponents();

        if ($plan['clear']) {
            self::clear();
        }

        if ($plan['build']) {
            app()->terminating(fn () => self::buildUnlessFailing(withAdmin: ! $adminFromCache));
        } elseif ($wanted && ! $adminFromCache && ! self::adminCached()) {
            // Filament's cache is written from this request's admin panel (see outdated()).
            app()->terminating(fn () => self::cacheAdmin());
        }
    }

    /**
     * The caches no longer match (e.g. a plugin was enabled): drop them now
     * and rebuild Laravel's after the response. Filament's are cached by the
     * next request: this request's admin panel still has the old plugins.
     */
    public static function outdated(): void
    {
        if (self::built()) {
            self::clear();
        }

        self::clearAdmin();

        if (self::wanted()) {
            Cache::forget(self::ATTEMPT);
            app()->terminating(fn () => self::buildUnlessFailing(withAdmin: false));
        }
    }

    /** Whether building the caches failed within the last hour (Site health). */
    public static function failing(): bool
    {
        return self::wanted() && ! self::built() && Cache::has(self::ATTEMPT);
    }

    /** @return bool whether every cache was built (otherwise none is left) */
    public static function build(bool $withAdmin = true): bool
    {
        putenv(self::BUILDING.'=1');

        try {
            foreach (self::BUILD as $command) {
                if (self::run($command) !== 0) {
                    self::clear();

                    return false;
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            self::clear();

            return false;
        } finally {
            putenv(self::BUILDING);
        }

        if ($withAdmin) {
            self::cacheAdmin();
        }

        return true;
    }

    public static function clear(): void
    {
        foreach (self::CLEAR as $command) {
            try {
                self::run($command);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        self::clearAdmin();
    }

    /**
     * Filament's component lists and Blade icon sets. Their Artisan commands
     * only exist on the command line, so their APIs are called directly.
     */
    private static function cacheAdmin(): void
    {
        try {
            foreach (Filament::getPanels() as $panel) {
                $panel->cacheComponents();
                self::forgetCompiledCopy($panel->getComponentCachePath());
            }
            app(IconsManifest::class)->write(app(IconsFactory::class)->all());
        } catch (Throwable $exception) {
            report($exception);
            self::clearAdmin();
        }
    }

    private static function clearAdmin(): void
    {
        try {
            foreach (Filament::getPanels() as $panel) {
                $panel->clearCachedComponents();
                self::forgetCompiledCopy($panel->getComponentCachePath());
            }
            app(IconsManifest::class)->delete();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * OPcache can keep running the old contents of a PHP file for a few
     * seconds after it changes, which would show the previous admin menu.
     * Hosts may restrict this function; the copy then expires by itself.
     */
    private static function forgetCompiledCopy(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private static function adminCached(): bool
    {
        $panel = Filament::getDefaultPanel();

        return is_file($panel->getComponentCachePath());
    }

    /**
     * True inside the fresh application `config:cache` boots to read the
     * configuration (from build() or `php artisan config:cache` / `optimize`).
     */
    public static function buildingConfigCache(): bool
    {
        return getenv(self::BUILDING) === '1' || app()->runningConsoleCommand('config:cache', 'optimize');
    }

    /**
     * Changes whenever something the caches are made from changes: `.env`,
     * the configuration and route files, the installed packages, the admin
     * pages (added or removed) and the plugins. A few dozen file dates, read
     * on every request while the caches are built.
     */
    public static function fingerprint(): string
    {
        $paths = [
            base_path('.env'),
            base_path('bootstrap/app.php'),
            base_path('vendor/composer/installed.php'),
            ...(glob(base_path('routes/*.php')) ?: []),
            ...(glob(base_path('config/*.php')) ?: []),
            ...(glob(base_path('plugins/*'), GLOB_ONLYDIR) ?: []),
            ...(glob(base_path('plugins/*/plugin.json')) ?: []),
            ...(glob(base_path('plugins/*/routes/*.php')) ?: []),
            ...self::directories(base_path('app/Filament')),
        ];

        return hash('xxh128', implode('|', array_map(fn (string $path) => $path.':'.@filemtime($path).':'.@filesize($path), $paths)));
    }

    /**
     * One build at a time and, while builds fail, at most one attempt per
     * hour. Without $withAdmin, Filament's list is dropped first: the route
     * cache must come from the pages that exist now (an update only removes
     * the files directly in bootstrap/cache), and the next request writes it.
     */
    private static function buildUnlessFailing(bool $withAdmin = true): void
    {
        if (! Cache::add(self::ATTEMPT, now()->getTimestamp(), self::RETRY_SECONDS)) {
            return;
        }

        if (! $withAdmin) {
            self::clearAdmin();
        }

        if (self::build($withAdmin)) {
            Cache::forget(self::ATTEMPT);
        }
    }

    /**
     * Runs an Artisan command in this process. `config:cache` boots a fresh
     * application and leaves it as the global instance; the request's own
     * application is put back afterwards.
     */
    private static function run(string $command): int
    {
        $app = app();

        try {
            return $app->make(Kernel::class)->call($command);
        } finally {
            Container::setInstance($app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($app);
        }
    }

    /** @return list<string> $root and every folder below it */
    private static function directories(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $found = [$root];
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            array_push($found, ...self::directories($directory));
        }

        return $found;
    }
}
