<?php

namespace App\Extensions;

use Illuminate\Translation\FileLoader;

/**
 * Laravel's file loader plus "override paths" for themes.
 *
 * A theme's lang/ folder is not added as a normal translation path: its
 * {locale}/{group}.php files are the theme's own namespaced strings, and as a
 * normal path they would be looked up for core keys too (__('Settings') would
 * find a theme's settings.php). Instead, from an override path only
 *
 *   {locale}.json                       overrides core copy, and
 *   vendor/{namespace}/{locale}/*.php   overrides a plugin's or another
 *                                       theme's strings
 *
 * are read, after core's own files, in the order the paths were added (so a
 * child theme, added after its parent, wins).
 */
class TranslationLoader extends FileLoader
{
    /** @var list<string> */
    protected array $overridePaths = [];

    public function addOverridePath(string $path): void
    {
        $this->overridePaths[] = $path;
    }

    /** @return list<string> */
    public function overridePaths(): array
    {
        return $this->overridePaths;
    }

    /**
     * @param  string  $locale
     * @return array<string, string>
     */
    protected function loadJsonPaths($locale)
    {
        $lines = parent::loadJsonPaths($locale);

        foreach ($this->overridePaths as $path) {
            if ($this->files->exists($file = "{$path}/{$locale}.json")) {
                $lines = array_replace($lines, (array) json_decode($this->files->get($file), true, flags: JSON_THROW_ON_ERROR));
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $lines
     * @param  string  $locale
     * @param  string  $group
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    protected function loadNamespaceOverrides(array $lines, $locale, $group, $namespace)
    {
        $lines = parent::loadNamespaceOverrides($lines, $locale, $group, $namespace);

        foreach ($this->overridePaths as $path) {
            if ($this->files->exists($file = "{$path}/vendor/{$namespace}/{$locale}/{$group}.php")) {
                $lines = array_replace_recursive($lines, $this->files->getRequire($file));
            }
        }

        return $lines;
    }
}
