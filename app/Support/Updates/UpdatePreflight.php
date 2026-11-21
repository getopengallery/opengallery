<?php

namespace App\Support\Updates;

use App\Enums\UpdateChannel;
use App\Support\AppVersion;
use App\Support\SiteSettings;

/**
 * What must be true before an update starts, checked for real where it can
 * be (a test file is actually renamed), so problems appear while the site is
 * still running.
 */
final class UpdatePreflight
{
    /** Spare room on top of the release and the backup, in bytes. */
    private const SPARE_BYTES = 100 * 1024 * 1024;

    /** Folders whose entries an update renames, relative to the project. */
    private const WRITABLE = ['', 'public', 'bootstrap', 'bootstrap/cache', 'database', 'plugins', 'themes'];

    /**
     * Why this server cannot install updates in one click, whatever the release.
     *
     * @return list<string>
     */
    public static function serverProblems(): array
    {
        $problems = [];

        foreach (['sodium', 'zip', 'zlib'] as $extension) {
            if (! extension_loaded($extension)) {
                $problems[] = __('The PHP extension :name is missing.', ['name' => $extension]);
            }
        }

        if (extension_loaded('sodium') && ! self::hasReleaseKey()) {
            $problems[] = __('This copy has no key to verify releases.');
        }

        if (! AppVersion::isRelease(AppVersion::current())) {
            $problems[] = __('This copy has no release version, so it cannot be compared with new versions.');
        }

        if (! self::usesStandardPublicFolder()) {
            $problems[] = __('The public folder of this gallery was moved, so updates must be installed by hand.');
        }

        if (self::opcacheCannotBeCleared()) {
            $problems[] = __('PHP keeps old code in OPcache and the gallery cannot clear it.');
        }

        return $problems;
    }

    /**
     * Everything that stops this release from being installed now.
     *
     * @return list<string>
     */
    public static function problems(ReleaseManifest $manifest, string $workDirectory, string $maintenanceFile): array
    {
        $problems = self::serverProblems();

        if (version_compare(PHP_VERSION, $manifest->requiresPhp, '<')) {
            $problems[] = __('This version needs PHP :required. This server runs :current.', ['required' => $manifest->requiresPhp, 'current' => PHP_VERSION]);
        }

        if ($manifest->requiresFrom !== null && version_compare(AppVersion::current(), $manifest->requiresFrom, '<')) {
            $problems[] = __('Update to version :version first.', ['version' => $manifest->requiresFrom]);
        }

        if (file_exists($maintenanceFile)) {
            $problems[] = __('The site is in maintenance mode.');
        }

        $free = function_exists('disk_free_space') ? @disk_free_space(dirname($workDirectory)) : false;

        if ($free !== false && $free < $manifest->size + (2 * $manifest->unpackedSize) + self::SPARE_BYTES) {
            $problems[] = __('There is not enough free disk space for the update.');
        }

        if (! self::canMoveFiles($workDirectory)) {
            $problems[] = __('The web server may not replace the gallery\'s files. Make the project folder writable, or install the update by hand.');
        }

        return $problems;
    }

    /** A real rename from the work folder into every folder an update changes. */
    private static function canMoveFiles(string $workDirectory): bool
    {
        if (! is_dir($workDirectory) && ! @mkdir($workDirectory, 0700, true) && ! is_dir($workDirectory)) {
            return false;
        }

        $name = '.og-update-test-'.bin2hex(random_bytes(6));
        $source = "{$workDirectory}/{$name}";

        if (@file_put_contents($source, 'test') === false) {
            return false;
        }

        foreach (self::WRITABLE as $folder) {
            $target = base_path($folder === '' ? $name : "{$folder}/{$name}");

            if (! @rename($source, $target) || ! @rename($target, $source)) {
                @unlink($target);
                @unlink($source);

                return false;
            }
        }

        foreach (['app', 'vendor', 'public/index.php'] as $path) {
            if (! is_writable(dirname(base_path($path))) || ! is_writable(base_path($path))) {
                @unlink($source);

                return false;
            }
        }

        @unlink($source);

        return true;
    }

    /** The updater replaces `public` in place, which only works where the site is served from it. */
    private static function usesStandardPublicFolder(): bool
    {
        if (realpath(public_path()) !== realpath(base_path('public'))) {
            return false;
        }

        if (PHP_SAPI === 'cli') {
            return true;
        }

        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        return $script !== false && dirname($script) === realpath(base_path('public'));
    }

    private static function opcacheCannotBeCleared(): bool
    {
        if (! function_exists('opcache_get_status') || ! (bool) ini_get('opcache.enable')) {
            return false;
        }

        return ! (bool) ini_get('opcache.validate_timestamps') && ! function_exists('opcache_reset');
    }

    /** A stable site needs the stable key; a dev site can install with either. */
    private static function hasReleaseKey(): bool
    {
        $channels = SiteSettings::updateChannel() === UpdateChannel::Stable ? [UpdateChannel::Stable] : UpdateChannel::cases();

        foreach ($channels as $channel) {
            if (ReleaseManifest::publicKey($channel) !== null) {
                return true;
            }
        }

        return false;
    }
}
