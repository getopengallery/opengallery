<?php

namespace App\Support\Updates;

/**
 * Which paths an update replaces. Each item is one rename, so the old version
 * can be put back exactly.
 *
 * Never touched: `.env`, `storage`, `bootstrap/cache`, the SQLite database,
 * files someone added to `public`, a customised `public/.htaccess`, themes and
 * plugins that are not part of the release, and bundled ones already newer
 * than the release's copy.
 */
final class ReleasePlan
{
    /** Top-level folders replaced entry by entry, because they also hold things that must stay. */
    private const MERGED = ['public', 'bootstrap', 'database', 'plugins', 'themes'];

    private const KEPT = ['.env', 'storage'];

    /** @return list<array{target: string, source: string, previous: string, existed: bool}> */
    public static function build(string $staging, string $base, string $previous): array
    {
        $plan = [];

        foreach (self::entries($staging) as $entry) {
            if (in_array($entry, self::KEPT, true)) {
                continue;
            }

            if (! in_array($entry, self::MERGED, true) || ! is_dir("{$staging}/{$entry}")) {
                $plan[] = self::item($entry, $staging, $base, $previous);

                continue;
            }

            foreach (self::entries("{$staging}/{$entry}") as $child) {
                if (self::keepsExisting($entry, $child, $staging, $base)) {
                    continue;
                }

                $plan[] = self::item("{$entry}/{$child}", $staging, $base, $previous);
            }
        }

        return $plan;
    }

    private static function keepsExisting(string $folder, string $child, string $staging, string $base): bool
    {
        return match ($folder) {
            'bootstrap' => $child === 'cache',
            'public' => $child === 'storage' || ($child === '.htaccess' && is_file("{$base}/public/.htaccess")),
            'database' => str_contains($child, '.sqlite'),
            'plugins' => self::installedIsNewer("{$base}/plugins/{$child}/plugin.json", "{$staging}/plugins/{$child}/plugin.json"),
            'themes' => self::installedIsNewer("{$base}/themes/{$child}/theme.json", "{$staging}/themes/{$child}/theme.json"),
            default => false,
        };
    }

    private static function installedIsNewer(string $installed, string $released): bool
    {
        $installedVersion = self::manifestVersion($installed);

        return $installedVersion !== null && version_compare($installedVersion, self::manifestVersion($released) ?? '0.0.0', '>');
    }

    private static function manifestVersion(string $file): ?string
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : null;
    }

    /** @return array{target: string, source: string, previous: string, existed: bool} */
    private static function item(string $path, string $staging, string $base, string $previous): array
    {
        return [
            'target' => "{$base}/{$path}",
            'source' => "{$staging}/{$path}",
            'previous' => "{$previous}/{$path}",
            'existed' => file_exists("{$base}/{$path}") || is_link("{$base}/{$path}"),
        ];
    }

    /** @return list<string> names in $folder, including hidden ones */
    private static function entries(string $folder): array
    {
        return array_values(array_diff(scandir($folder) ?: [], ['.', '..']));
    }
}
