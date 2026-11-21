<?php

namespace App\Extensions;

use App\Enums\Permission;
use App\Models\User;
use App\Support\ProductionCaches;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Installs a theme or plugin from a zip file uploaded in the admin
 * (Appearance & extensions → Themes / Plugins → Upload).
 *
 * The zip holds the extension's folder ("watermark/plugin.json"), or the
 * folder's contents with the manifest at the top ("plugin.json"). The folder
 * name, or without one the zip's file name, becomes the slug.
 *
 * Every entry is checked before anything is written: no paths that leave the
 * folder, no links, at most MAX_FILES entries and MAX_UNPACKED_BYTES unpacked.
 * The files are unpacked next to the installed extensions under a hidden name
 * (glob('*') skips it) and then renamed into place, so a failed upload never
 * leaves half an extension behind. Replacing an installed one swaps the
 * folders the same way.
 *
 * Uploaded extensions run code on the server (plugin PHP, theme Blade views):
 * only people with Permission::InstallExtensions may upload, and only while
 * config('gallery.extension_uploads') is on (see allowed()).
 */
class ExtensionInstaller
{
    /** Largest zip file accepted, in KB. */
    public const MAX_UPLOAD_KB = 51200;

    public const MAX_FILES = 5000;

    public const MAX_UNPACKED_BYTES = 200 * 1024 * 1024;

    /** Entries packers add that are not part of the extension. */
    private const JUNK = ['__MACOSX', '.DS_Store', 'Thumbs.db'];

    public static function allowed(?User $user = null): bool
    {
        $user ??= auth()->user();

        return (bool) config('gallery.extension_uploads') && ($user?->can(Permission::InstallExtensions->value) ?? false);
    }

    /**
     * @param  string  $zipPath  the uploaded file on disk
     * @param  string  $fileName  its original name, for the slug when the zip has no folder
     *
     * @throws ExtensionInstallFailed
     */
    public function installTheme(string $zipPath, string $fileName, bool $replace = false): Manifest
    {
        $themes = app(ThemeManager::class);
        $theme = $this->install($zipPath, $fileName, 'theme.json', $themes->path(), $replace);
        $themes->forget();

        return $theme;
    }

    /**
     * An uploaded plugin stays off until someone turns it on. Replacing a
     * plugin that is on rebuilds the production caches, like turning it on.
     *
     * @throws ExtensionInstallFailed
     */
    public function installPlugin(string $zipPath, string $fileName, bool $replace = false): Manifest
    {
        $plugins = app(PluginManager::class);
        $plugin = $this->install($zipPath, $fileName, 'plugin.json', $plugins->path(), $replace);
        $plugins->forget();

        if ($plugins->isEnabled($plugin->slug)) {
            ProductionCaches::outdated();
        }

        return $plugin;
    }

    /** @throws ExtensionInstallFailed */
    private function install(string $zipPath, string $fileName, string $manifestFile, string $root, bool $replace): Manifest
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ExtensionInstallFailed(__('This server cannot unpack zip files (the PHP extension "zip" is missing). Copy the folder to the server instead.'));
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ExtensionInstallFailed(__('The file is not a zip archive.'));
        }

        try {
            $entries = $this->entries($zip);
            [$prefix, $slug] = $this->locate($entries, $manifestFile, $fileName);
            $this->checkManifest($zip, $entries[$prefix.$manifestFile]['index'], $manifestFile);

            $target = $root.'/'.$slug;
            if (file_exists($target) && ! $replace) {
                throw new ExtensionInstallFailed(__('":slug" is already installed. Turn on "Replace the installed version" to update it.', ['slug' => $slug]));
            }

            if (! is_dir($root) || ! is_writable($root)) {
                throw new ExtensionInstallFailed(__('The folder :folder on the server is not writable. Copy the folder to the server instead, or ask your host to make it writable.', ['folder' => '/'.basename($root)]));
            }

            $staging = $root.'/.'.$slug.'.upload-'.Str::lower(Str::random(8));

            try {
                $this->extract($zip, $entries, $prefix, $staging);
                $this->moveIntoPlace($staging, $target, $root, $slug);
            } finally {
                File::deleteDirectory($staging);
            }
        } finally {
            $zip->close();
        }

        return Manifest::load($target.'/'.$manifestFile)
            ?? throw new ExtensionInstallFailed(__('The :file in the zip file is not valid.', ['file' => $manifestFile]));
    }

    /**
     * Every entry of the zip, checked, without packer junk.
     *
     * @return array<string, array{index: int, dir: bool}> keyed by path ("watermark/src/A.php"; folders without a trailing slash)
     *
     * @throws ExtensionInstallFailed
     */
    private function entries(ZipArchive $zip): array
    {
        $unsafe = new ExtensionInstallFailed(__('The zip file contains links or paths outside its folder and was not installed.'));
        $entries = [];
        $size = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            $path = trim($name, '/');
            $segments = explode('/', $path);

            if ($path === '' || array_intersect($segments, self::JUNK) !== []) {
                continue;
            }

            if (str_starts_with($name, '/') || str_contains($name, "\0") || preg_match('/^[A-Za-z]:/', $name) === 1
                || array_intersect($segments, ['', '.', '..']) !== []) {
                throw $unsafe;
            }

            // Symbolic links could point anywhere on the server.
            if ($zip->getExternalAttributesIndex($index, $system, $attributes)
                && $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0xF000) === 0xA000) {
                throw $unsafe;
            }

            $size += (int) ($stat['size'] ?? 0);
            if (count($entries) >= self::MAX_FILES || $size > self::MAX_UNPACKED_BYTES) {
                throw new ExtensionInstallFailed(__('The zip file is too large: at most :files files and :size MB unpacked.', ['files' => self::MAX_FILES, 'size' => self::MAX_UNPACKED_BYTES / 1024 / 1024]));
            }

            $entries[$path] = ['index' => $index, 'dir' => str_ends_with($name, '/')];
        }

        return $entries;
    }

    /**
     * Where the manifest is and which slug the extension gets.
     *
     * @param  array<string, array{index: int, dir: bool}>  $entries
     * @return array{0: string, 1: string} path prefix of the extension's files ("" or "watermark/"), slug
     *
     * @throws ExtensionInstallFailed
     */
    private function locate(array $entries, string $manifestFile, string $fileName): array
    {
        if (isset($entries[$manifestFile]) && ! $entries[$manifestFile]['dir']) {
            // "theme (1).zip", as browsers name a second download, installs as "theme".
            $name = preg_replace('/\s*\(\d+\)$/', '', pathinfo($fileName, PATHINFO_FILENAME));

            return ['', $this->slug(Str::slug((string) $name))];
        }

        $tops = array_unique(array_map(fn (string $path): string => explode('/', $path)[0], array_keys($entries)));
        $top = count($tops) === 1 ? reset($tops) : null;

        if ($top === null || ! isset($entries[$top.'/'.$manifestFile]) || $entries[$top.'/'.$manifestFile]['dir']) {
            throw new ExtensionInstallFailed(__('The zip file has no :file, neither at the top nor in a single folder.', ['file' => $manifestFile]));
        }

        return [$top.'/', $this->slug($top)];
    }

    /** @throws ExtensionInstallFailed */
    private function slug(string $slug): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $slug) !== 1) {
            throw new ExtensionInstallFailed(__('":name" cannot be used as a folder name. Use letters, digits and hyphens only.', ['name' => $slug]));
        }

        return $slug;
    }

    /** @throws ExtensionInstallFailed */
    private function checkManifest(ZipArchive $zip, int $index, string $manifestFile): void
    {
        $data = json_decode((string) $zip->getFromIndex($index), true);

        if (! is_array($data) || ! is_string($data['name'] ?? null) || trim($data['name']) === '') {
            throw new ExtensionInstallFailed(__('The :file in the zip file is not valid: it needs at least a "name".', ['file' => $manifestFile]));
        }
    }

    /**
     * Unpacks the extension's files into $staging, counting the bytes really
     * written rather than trusting the sizes the zip states.
     *
     * @param  array<string, array{index: int, dir: bool}>  $entries
     *
     * @throws ExtensionInstallFailed
     */
    private function extract(ZipArchive $zip, array $entries, string $prefix, string $staging): void
    {
        File::ensureDirectoryExists($staging);
        $written = 0;

        foreach ($entries as $path => $entry) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            $destination = $staging.'/'.substr($path, strlen($prefix));

            if ($entry['dir']) {
                File::ensureDirectoryExists($destination);

                continue;
            }

            File::ensureDirectoryExists(dirname($destination));
            $in = $zip->getStreamIndex($entry['index']);
            $out = fopen($destination, 'wb');

            if ($in === false || $out === false) {
                throw new ExtensionInstallFailed(__('The zip file could not be unpacked.'));
            }

            try {
                while (! feof($in)) {
                    $chunk = (string) fread($in, 1024 * 1024);
                    $written += strlen($chunk);

                    if ($written > self::MAX_UNPACKED_BYTES) {
                        throw new ExtensionInstallFailed(__('The zip file is too large: at most :files files and :size MB unpacked.', ['files' => self::MAX_FILES, 'size' => self::MAX_UNPACKED_BYTES / 1024 / 1024]));
                    }

                    fwrite($out, $chunk);
                }
            } finally {
                fclose($in);
                fclose($out);
            }
        }
    }

    /** @throws ExtensionInstallFailed */
    private function moveIntoPlace(string $staging, string $target, string $root, string $slug): void
    {
        $failed = new ExtensionInstallFailed(__('The files could not be moved into place. Check that the server may write to the folder.'));

        if (! file_exists($target)) {
            if (! @rename($staging, $target)) {
                throw $failed;
            }

            return;
        }

        $previous = $root.'/.'.$slug.'.replaced-'.Str::lower(Str::random(8));
        if (! @rename($target, $previous)) {
            throw $failed;
        }

        if (! @rename($staging, $target)) {
            @rename($previous, $target);

            throw $failed;
        }

        File::deleteDirectory($previous);
    }
}
