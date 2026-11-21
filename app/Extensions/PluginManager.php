<?php

namespace App\Extensions;

use App\Models\Setting;
use App\Support\ProductionCaches;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Translation\Translator;
use Throwable;

/**
 * Plugins live in /plugins/{slug}:
 *
 *   plugin.json   {
 *                   "name": "Watermark",
 *                   "version": "1.0.0",
 *                   "description": "...",
 *                   "autoload": { "Plugins\\Watermark\\": "src/" },
 *                   "provider": "Plugins\\Watermark\\WatermarkServiceProvider",
 *                   "settings": "Plugins\\Watermark\\Filament\\Settings"   (optional)
 *                 }
 *   src/          PHP code (PSR-4, registered only while enabled)
 *   lang/         optional: the plugin's own strings in {locale}/{group}.php,
 *                 used as __('{slug}::group.key') (registered for every
 *                 installed plugin, so the admin can show translated names)
 *
 * A plugin is an ordinary Laravel service provider. It hooks into the core
 * through events, view composers, routes, App\Extensions\LoginOptions,
 * App\Extensions\PluginSettings, etc. Plugins must not modify core files.
 *
 * Enabled plugins are registered during the application's *register* phase
 * (see AppServiceProvider::register), so their providers can contribute to
 * anything that is configured later, e.g. the admin panel. A plugin that
 * throws while loading is logged and skipped instead of taking the site down.
 *
 * Plugins are enabled in Admin → Plugins (stored in the settings table) or,
 * for deployments, with GALLERY_PLUGINS=slug-a,slug-b in .env.
 */
class PluginManager
{
    public const SETTING = 'plugins.enabled';

    /** @var Collection<string, Manifest>|null */
    private ?Collection $plugins = null;

    /** @var list<string> */
    private array $loaded = [];

    /** @var list<string>|null */
    private ?array $enabled = null;

    public function __construct(private Application $app, private string $path) {}

    /** The folder that holds the plugins. */
    public function path(): string
    {
        return $this->path;
    }

    /** Reads the plugins folder again on next use, e.g. after a plugin was uploaded. */
    public function forget(): void
    {
        $this->plugins = null;
    }

    /** @return Collection<string, Manifest> */
    public function all(): Collection
    {
        return $this->plugins ??= collect(glob($this->path.'/*/plugin.json') ?: [])
            ->map(fn (string $file) => Manifest::load($file))
            ->filter()
            ->keyBy('slug');
    }

    /** @return list<string> */
    public function enabledSlugs(): array
    {
        return $this->enabled ??= array_values(array_unique([...$this->storedSlugs(), ...$this->configuredSlugs()]));
    }

    public function isEnabled(string $slug): bool
    {
        return in_array($slug, $this->enabledSlugs(), true);
    }

    /** Enabled through GALLERY_PLUGINS; cannot be turned off in the admin. */
    public function isEnabledByConfig(string $slug): bool
    {
        return in_array($slug, $this->configuredSlugs(), true);
    }

    public function enable(string $slug): void
    {
        abort_unless($this->all()->has($slug), 404);

        Setting::put(self::SETTING, array_values(array_unique([...$this->storedSlugs(), $slug])));
        $this->enabled = null;
        // Plugins add routes, configuration and admin pages: the production caches are made anew.
        ProductionCaches::outdated();
    }

    public function disable(string $slug): void
    {
        Setting::put(self::SETTING, array_values(array_diff($this->storedSlugs(), [$slug])));
        $this->enabled = null;
        ProductionCaches::outdated();
    }

    /** @return list<string> slugs that were loaded this request */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /** Admin page class for a plugin's settings, if it declares one and is loaded. */
    public function settingsPage(string $slug): ?string
    {
        $class = $this->all()->get($slug)?->get('settings');

        return in_array($slug, $this->loaded, true) && is_string($class) && class_exists($class) ? $class : null;
    }

    /** @return list<class-string> settings pages of all loaded plugins, for the admin panel */
    public function settingsPages(): array
    {
        return array_values(array_filter(array_map(fn (string $slug) => $this->settingsPage($slug), $this->loaded)));
    }

    /**
     * Registers the autoloader and service provider of every enabled plugin.
     * Called from AppServiceProvider::register().
     */
    public function register(): void
    {
        $this->app->resolved('translator')
            ? $this->registerTranslations($this->app['translator'])
            : $this->app->afterResolving('translator', fn (Translator $translator) => $this->registerTranslations($translator));

        foreach ($this->enabledSlugs() as $slug) {
            $this->load($slug);
        }
    }

    private function registerTranslations(Translator $translator): void
    {
        foreach ($this->all() as $plugin) {
            if (is_dir($plugin->path.'/lang')) {
                $translator->addNamespace(self::translationNamespace($plugin->slug), $plugin->path.'/lang');
            }
        }
    }

    /** Namespace of a plugin's own strings: __('identity-providers::settings.title'). */
    public static function translationNamespace(string $slug): string
    {
        return $slug;
    }

    /** Loads a single plugin; safe to call more than once. */
    public function load(string $slug): bool
    {
        if (in_array($slug, $this->loaded, true)) {
            return true;
        }

        $plugin = $this->all()->get($slug);

        if (! $plugin) {
            return false;
        }

        try {
            $this->registerAutoloader($plugin);

            if ($provider = $plugin->get('provider')) {
                $this->app->register($provider);
            }

            $this->loaded[] = $slug;

            return true;
        } catch (Throwable $e) {
            Log::error("Plugin [{$slug}] failed to load: {$e->getMessage()}", ['exception' => $e]);

            return false;
        }
    }

    /**
     * Read straight from the database: this runs in the register phase,
     * before Eloquent is booted. A missing table (fresh install) means none.
     *
     * @return list<string>
     */
    private function storedSlugs(): array
    {
        try {
            $value = $this->app['db']->table('settings')->where('key', self::SETTING)->value('value');
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter((array) json_decode((string) $value, true), 'is_string'));
    }

    /** @return list<string> */
    private function configuredSlugs(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('gallery.plugins')))));
    }

    private function registerAutoloader(Manifest $plugin): void
    {
        $map = (array) $plugin->get('autoload', []);

        if ($map === []) {
            return;
        }

        $loader = new ClassLoader;
        foreach ($map as $namespace => $dir) {
            $loader->addPsr4($namespace, $plugin->path.'/'.trim($dir, '/'));
        }
        $loader->register();
    }
}
