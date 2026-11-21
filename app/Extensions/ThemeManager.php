<?php

namespace App\Extensions;

use App\Models\Setting;
use Illuminate\Mail\Markdown;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Themes live in /themes/{slug}:
 *
 *   theme.json   manifest:
 *                  variables / variables_dark   CSS tokens (hex or "R G B")
 *                  blocks                       block variants, e.g. {"photo-grid": "square"}
 *                  styles                       extra stylesheets from assets/
 *                  admin                        {"primary": "#hex"} for the admin panel
 *                  parent                       inherit views, blocks, palettes and settings
 *                  settings                     the theme's settings form (merged over the parent's)
 *   views/       optional Blade overrides with the same relative paths as
 *                resources/views (e.g. views/blocks/album-tile.blade.php),
 *                plus views/errors/* and views/vendor/{namespace}/* (see boot)
 *   lang/        optional translations: {locale}.json overrides core copy;
 *                {locale}/{group}.php holds the theme's own strings, used as
 *                __('theme-{slug}::group.key') (see translationNamespace())
 *   assets/      static files, served at /themes/{slug}/...
 *
 * Light and dark mode live in one theme: "variables" apply by default,
 * "variables_dark" when <html> has the "dark" class (shared with Filament).
 */
class ThemeManager
{
    public const SETTING = 'theme';

    /** Setting key prefix for the options chosen in a theme's settings form. */
    public const OPTIONS = 'theme.options.';

    /** Uploaded theme images live on this disk under "themes/{slug}". */
    public const UPLOAD_DISK = 'local';

    /** @var Collection<string, Manifest>|null */
    private ?Collection $themes = null;

    /** @var array<string, list<array<string, mixed>>> merged settings sections by theme slug */
    private array $sections = [];

    /** @var array<string, array<string, mixed>> settings field defaults by theme slug */
    private array $defaults = [];

    public function __construct(private string $path, private string $fallback) {}

    /** The folder that holds the themes. */
    public function path(): string
    {
        return $this->path;
    }

    /** Reads the themes folder again on next use, e.g. after a theme was uploaded. */
    public function forget(): void
    {
        $this->themes = null;
        $this->sections = [];
        $this->defaults = [];
    }

    /** @return Collection<string, Manifest> */
    public function all(): Collection
    {
        return $this->themes ??= collect(glob($this->path.'/*/theme.json') ?: [])
            ->map(fn (string $file) => Manifest::load($file))
            ->filter()
            ->keyBy('slug');
    }

    public function active(): ?Manifest
    {
        $slug = Setting::get(self::SETTING, $this->fallback);

        return $this->all()->get($slug) ?? $this->all()->get($this->fallback);
    }

    public function activate(string $slug): void
    {
        abort_unless($this->all()->has($slug), 404);

        Setting::put(self::SETTING, $slug);
    }

    /**
     * The active theme followed by its parents.
     *
     * @return list<Manifest>
     */
    public function chain(): array
    {
        return $this->chainFor($this->active());
    }

    /** @return list<Manifest> */
    private function chainFor(?Manifest $theme): array
    {
        $chain = [];

        while ($theme && count($chain) < 5 && ! in_array($theme, $chain, true)) {
            $chain[] = $theme;
            $theme = $theme->get('parent') ? $this->all()->get($theme->get('parent')) : null;
        }

        return $chain;
    }

    /**
     * Wire the theme chain (child before parent) in front of core:
     *
     *   views/                        any view in resources/views
     *   views/errors/                 error pages (404, 500, ...)
     *   views/vendor/{namespace}/     package views, e.g. vendor/notifications
     *   views/vendor/mail/            e-mail layout and components
     *   lang/{locale}.json            overrides of core copy (the active chain only)
     *   lang/{locale}/{group}.php     the theme's own strings, namespace "theme-{slug}"
     *                                 (every installed theme, so the admin can list them)
     *   lang/vendor/{ns}/{locale}/... overrides of a parent theme's or a plugin's strings
     *                                 (the active chain only; see TranslationLoader)
     *
     * Pagination (->links()) renders the themeable "pagination" block.
     */
    public function boot(): void
    {
        $translator = app('translator');
        foreach ($this->all() as $theme) {
            if (is_dir($theme->path.'/lang')) {
                $translator->addNamespace(self::translationNamespace($theme->slug), $theme->path.'/lang');
            }
        }

        foreach (array_reverse($this->chain()) as $theme) {
            $views = $theme->path.'/views';

            if (is_dir($views)) {
                View::getFinder()->prependLocation($views);

                // Error pages resolve "errors::" from these paths at render time.
                config()->prepend('view.paths', $views);

                foreach (glob($views.'/vendor/*', GLOB_ONLYDIR) ?: [] as $dir) {
                    View::prependNamespace(basename($dir), $dir);
                }

                if (is_dir($views.'/vendor/mail')) {
                    config()->prepend('mail.markdown.paths', $views.'/vendor/mail');
                }
            }

            // Read after core's files, so the theme (and a child after its parent) wins.
            $loader = $translator->getLoader();
            if (is_dir($theme->path.'/lang') && $loader instanceof TranslationLoader) {
                $loader->addOverridePath($theme->path.'/lang');
            }
        }

        // Lines loaded before the theme's files were known are read again.
        $translator->setLoaded([]);

        if (app()->resolved(Markdown::class)) {
            app(Markdown::class)->loadComponentsFrom((array) config('mail.markdown.paths', []));
        }

        Paginator::defaultView($this->blockView('pagination'));

        View::share('activeTheme', $this->active());
    }

    /** Namespace of a theme's own strings: __('theme-default::settings.palette'). */
    public static function translationNamespace(string $slug): string
    {
        return 'theme-'.$slug;
    }

    /** A theme setting, looked up through the parent chain. */
    public function setting(string $key, mixed $default = null): mixed
    {
        foreach ($this->chain() as $theme) {
            if (($value = $theme->get($key)) !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * View name for a block: "blocks.{name}.{variant}" if the theme picks a
     * variant that exists, otherwise "blocks.{name}". A theme can also
     * replace a block entirely by shipping views/blocks/{name}.blade.php.
     */
    public function blockView(string $name): string
    {
        // A settings field bound to this block ("block": "site-header") wins
        // over the static choice in theme.json ("blocks": {...}).
        $field = collect($this->settingFields())->first(fn (array $f) => ($f['block'] ?? null) === $name);
        $variant = $field ? $this->option($field['key']) : $this->setting("blocks.{$name}");

        if (is_string($variant) && preg_match('/^[a-z0-9-]+$/', $variant) && View::exists("blocks.{$name}.{$variant}")) {
            return "blocks.{$name}.{$variant}";
        }

        return View::exists("blocks.{$name}") ? "blocks.{$name}" : "blocks.{$name}.default";
    }

    /**
     * Inline CSS with the active theme's light and dark tokens, after applying
     * the chosen palette, custom colours and fields bound to a CSS variable.
     */
    public function cssVariables(): string
    {
        [$light, $dark] = $this->resolvedVariables();

        $css = ':root { '.$this->renderVariables($light).' }';

        if ($dark) {
            $css .= ' :root.dark { '.$this->renderVariables($dark).' }';
        }

        return $css;
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} light and dark variables */
    public function resolvedVariables(): array
    {
        $light = (array) $this->mergedSetting('variables');
        $dark = (array) $this->mergedSetting('variables_dark');
        $options = $this->options();

        foreach ($this->settingFields() as $field) {
            if (! $this->fieldActive($field, $options)) {
                continue;
            }

            $value = $options[$field['key']] ?? null;

            if ($field['type'] === 'palette' && is_string($value)) {
                $palette = (array) ($this->palettes()[$value] ?? []);
                $light = array_merge($light, (array) ($palette['variables'] ?? []));
                $dark = array_merge($dark, (array) ($palette['variables_dark'] ?? []));
            }

            if ($field['type'] === 'colors' && is_array($value)) {
                foreach ((array) ($value['light'] ?? []) as $token => $hex) {
                    filled($hex) && $light["--og-{$token}"] = $hex;
                }
                foreach ((array) ($value['dark'] ?? []) as $token => $hex) {
                    filled($hex) && $dark["--og-{$token}"] = $hex;
                }
            }

            if (isset($field['css_variable']) && is_scalar($value) && $value !== '') {
                $light[$field['css_variable']] = (string) $value;
            }
        }

        return [$light, $dark];
    }

    /** Custom CSS from the theme's "css" fields; cannot break out of its <style> tag. */
    public function customCss(): string
    {
        $options = $this->options();

        return collect($this->settingFields())
            ->filter(fn (array $field) => $field['type'] === 'css' && $this->fieldActive($field, $options))
            ->map(fn (array $field) => (string) ($options[$field['key']] ?? ''))
            ->filter()
            ->map(fn (string $css) => str_ireplace('</', '<\\/', $css))
            ->implode("\n");
    }

    // ---- Theme settings (options chosen in the admin) ----------------------

    /**
     * The settings form a theme declares in theme.json ("settings": a list of
     * sections with "fields"), merged over its parent's (see mergeSections).
     *
     * @return list<array{label: string, description?: string, fields: list<array<string, mixed>>}>
     */
    public function settingSections(?string $slug = null): array
    {
        $theme = $slug ? $this->all()->get($slug) : $this->active();

        if (isset($this->sections[$theme?->slug ?? ''])) {
            return $this->sections[$theme?->slug ?? ''];
        }

        $sections = array_reduce(
            array_reverse($this->chainFor($theme)),
            fn (array $merged, Manifest $t) => $this->mergeSections($merged, (array) $t->get('settings', [])),
            [],
        );

        return $this->sections[$theme?->slug ?? ''] = array_values(array_filter($sections, fn (array $s) => $s['fields'] !== []));
    }

    /**
     * A child theme extends its parent's settings: sections match by "key"
     * (or "label"), fields by "key". A match merges attributes (child wins,
     * "options" are added to the parent's), anything new is appended, and
     * "remove": true drops the parent's section or field.
     *
     * @param  array<int, mixed>  $parent
     * @param  array<int, mixed>  $child
     * @return list<array<string, mixed>>
     */
    private function mergeSections(array $parent, array $child): array
    {
        $sections = collect($parent)->keyBy(fn (array $s) => $s['key'] ?? $s['label'] ?? '')->all();

        foreach ($child as $section) {
            if (! is_array($section)) {
                continue;
            }

            $id = $section['key'] ?? $section['label'] ?? '';

            if ($section['remove'] ?? false) {
                unset($sections[$id]);

                continue;
            }

            $base = $sections[$id] ?? ['fields' => []];
            $sections[$id] = array_replace($base, $section, [
                'fields' => $this->mergeFields($base['fields'], (array) ($section['fields'] ?? [])),
            ]);
        }

        return array_values($sections);
    }

    /**
     * @param  list<array<string, mixed>>  $parent
     * @param  array<int, mixed>  $child
     * @return list<array<string, mixed>>
     */
    private function mergeFields(array $parent, array $child): array
    {
        $fields = collect($parent)->keyBy('key')->all();

        foreach ($child as $field) {
            if (! is_array($field) || ! isset($field['key'])) {
                continue;
            }

            $key = $field['key'];

            if ($field['remove'] ?? false) {
                unset($fields[$key]);

                continue;
            }

            // A new field needs a type; an existing one may change any attribute.
            if (! isset($fields[$key]) && ! isset($field['type'])) {
                continue;
            }

            $base = $fields[$key] ?? [];
            $merged = array_replace($base, $field);

            if (is_array($base['options'] ?? null) && is_array($field['options'] ?? null)) {
                $merged['options'] = array_replace($base['options'], $field['options']);
            }

            $fields[$key] = $merged;
        }

        return array_values($fields);
    }

    /** @return list<array<string, mixed>> */
    public function settingFields(?string $slug = null): array
    {
        return collect($this->settingSections($slug))
            ->flatMap(fn (array $section) => $section['fields'])
            ->filter(fn ($field) => is_array($field) && isset($field['key'], $field['type']))
            ->values()
            ->all();
    }

    /** @return array<string, array<string, mixed>> palettes by key, child overriding parent */
    public function palettes(?string $slug = null): array
    {
        return (array) $this->mergedSetting('palettes', $slug);
    }

    /**
     * Chosen option values for a theme, over the field defaults.
     *
     * @return array<string, mixed>
     */
    public function options(?string $slug = null): array
    {
        $slug ??= $this->active()?->slug;

        // Field defaults come from theme.json only, so they are worked out once per theme.
        $defaults = $this->defaults[$slug ?? ''] ??= collect($this->settingFields($slug))
            ->mapWithKeys(fn (array $f) => [$f['key'] => $f['default'] ?? null])
            ->all();

        return array_merge($defaults, (array) Setting::get(self::OPTIONS.$slug, []));
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options()[$key] ?? $default;
    }

    /** @param array<string, mixed> $values */
    public function saveOptions(string $slug, array $values): void
    {
        Setting::put(self::OPTIONS.$slug, $values);
    }

    /** URL of an image chosen in the theme settings, if any. */
    public function imageUrl(string $key): ?string
    {
        $path = $this->option($key);

        if (! is_string($path) || $path === '' || ! Storage::disk(self::UPLOAD_DISK)->exists($path)) {
            return null;
        }

        return route('theme.upload', ['path' => $path]);
    }

    /** Whether a field's "visible_if" conditions hold for the given options. */
    public function fieldActive(array $field, array $options): bool
    {
        foreach ((array) ($field['visible_if'] ?? []) as $key => $expected) {
            if (($options[$key] ?? null) != $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * URLs of the active theme's extra stylesheets. Browsers keep them for a
     * week (ThemeAssetController), so each URL carries a version (`v`) that
     * changes with the file: an updated theme shows at once.
     *
     * @return list<string>
     */
    public function stylesheets(): array
    {
        $theme = $this->active();

        return collect((array) $theme?->get('styles', []))
            ->map(function (string $file) use ($theme) {
                $path = $this->assetPath($theme->slug, $file);
                $version = $path ? substr(md5(filemtime($path).':'.filesize($path)), 0, 8) : null;

                return route('theme.asset', array_filter(['theme' => $theme->slug, 'path' => $file, 'v' => $version]));
            })
            ->values()
            ->all();
    }

    public function assetPath(string $slug, string $path): ?string
    {
        $theme = $this->all()->get($slug);
        if (! $theme) {
            return null;
        }

        $base = realpath($theme->path.'/assets');
        $file = $base ? realpath($base.'/'.$path) : false;

        // Guard against ../ traversal out of the assets folder.
        return $file && str_starts_with($file, $base.DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    /** @return array<string, mixed> parent values overridden by child values */
    private function mergedSetting(string $key, ?string $slug = null): array
    {
        $chain = $slug ? $this->chainFor($this->all()->get($slug)) : $this->chain();

        return collect(array_reverse($chain))
            ->reduce(fn (array $carry, Manifest $t) => array_merge($carry, (array) $t->get($key, [])), []);
    }

    /** @param array<string, mixed> $vars */
    private function renderVariables(array $vars): string
    {
        return collect($vars)
            ->map(fn ($value) => $this->normalizeValue((string) $value))
            ->filter(fn (?string $value, $key) => $value !== null && preg_match('/^--og-[a-z0-9-]+$/', $key))
            ->map(fn (string $value, string $key) => "{$key}: {$value};")
            ->implode(' ');
    }

    /** "#RRGGBB" → "R G B"; other safe values pass through; unsafe → null. */
    private function normalizeValue(string $value): ?string
    {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $value, $m)) {
            return implode(' ', array_map('hexdec', array_slice($m, 1)));
        }

        return preg_match('/^[a-zA-Z0-9 ,.%()\/\'"-]+$/', $value) ? $value : null;
    }
}
