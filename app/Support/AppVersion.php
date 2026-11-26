<?php

namespace App\Support;

/**
 * The version of this copy of OpenGallery.
 *
 * The root `VERSION` file is the only source. In the repository it names the
 * version in development (`0.2.0-dev`); `php artisan gallery:release` writes
 * the released version into the copy it packs.
 */
final class AppVersion
{
    /** Shown when the `VERSION` file is missing or unreadable. */
    public const UNKNOWN = 'dev';

    private const PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/';

    /** The file to read; tests point it elsewhere. */
    private static ?string $file = null;

    public static function current(): string
    {
        $file = self::$file ?? base_path('VERSION');
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match(self::PATTERN, $version) === 1 ? $version : self::UNKNOWN;
    }

    /** Whether $version can be compared with releases: `1.2.0`, or with a suffix such as `1.3.0-dev.4`. */
    public static function isRelease(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(-(dev|alpha|beta|rc)(\.\d+)?)?$/', $version) === 1;
    }

    /**
     * The GitHub page of $version's release, when it has one: a numbered
     * release (`1.2.0`, `1.3.0-dev.4`) of the configured repository. The
     * development marker in the repository (`0.2.0-dev`) and `dev` have none.
     */
    public static function releaseUrl(?string $version = null): ?string
    {
        $version ??= self::current();
        $repository = trim((string) config('gallery.update_repository'));

        if ($repository === '' || ! self::isRelease($version) || preg_match('/-(dev|alpha|beta|rc)$/', $version) === 1) {
            return null;
        }

        return "https://github.com/{$repository}/releases/tag/v{$version}";
    }

    /** The version a release tag stands for: `v1.2.0` is version `1.2.0`. */
    public static function fromTag(string $tag): string
    {
        return (string) preg_replace('/^v(?=\d)/', '', $tag);
    }

    /** Points the version at another file (tests); null restores the root `VERSION` file. */
    public static function useFile(?string $file): void
    {
        self::$file = $file;
    }
}
