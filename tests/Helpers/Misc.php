<?php

use App\Extensions\DashboardWidgets;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use App\Support\ProductionCaches;
use App\Support\Updates\DatabaseBackup;
use BladeUI\Icons\IconsManifest;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/** Points every cache this test could write at a temporary folder; returns the folder. */
function temporaryCacheFolder(): string
{
    $folder = sys_get_temp_dir().'/og-caches-'.Str::random(8);
    mkdir($folder.'/filament/panels', 0777, true);
    config(['filament.cache_path' => $folder.'/filament']);
    app()->instance(IconsManifest::class, new IconsManifest(new Filesystem, $folder.'/blade-icons.php'));
    test()->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($folder));

    return $folder;
}

/** This request loaded its configuration from the cache, made from the code as it is now (or from older code). */
function cachesBuilt(bool $outdated = false): void
{
    app()->instance('config_loaded_from_cache', true);
    config(['gallery.code_fingerprint' => $outdated ? 'older code' : ProductionCaches::fingerprint()]);
}

/**
 * Records the Artisan commands the caches run instead of running them.
 *
 * @return ArrayObject<int, string>
 */
function recordCacheCommands(int $exitCode = 0): ArrayObject
{
    $commands = new ArrayObject;
    test()->mock(Kernel::class, fn (MockInterface $mock) => $mock->shouldReceive('call')
        ->andReturnUsing(function (string $command) use ($commands, $exitCode) {
            $commands[] = $command;

            return str_ends_with($command, ':cache') ? $exitCode : 0;
        }));

    return $commands;
}

/**
 * Uses its own connection, because a backup and restore run real DDL. CI points
 * it at MySQL and MariaDB through the BACKUP_TEST_* variables. That database
 * must be dedicated to this test: its tables are dropped.
 *
 * @return array<string, mixed>
 */
function backupConnectionConfig(string $folder): array
{
    $driver = getenv('BACKUP_TEST_DRIVER') ?: 'sqlite';

    if ($driver === 'sqlite') {
        touch("{$folder}/backup-test.sqlite");

        return ['driver' => 'sqlite', 'database' => "{$folder}/backup-test.sqlite", 'prefix' => '', 'foreign_key_constraints' => true];
    }

    return [
        'driver' => $driver,
        'host' => getenv('BACKUP_TEST_HOST') ?: '127.0.0.1',
        'port' => getenv('BACKUP_TEST_PORT') ?: '3306',
        'database' => getenv('BACKUP_TEST_DATABASE') ?: 'backup_test',
        'username' => getenv('BACKUP_TEST_USERNAME') ?: 'root',
        'password' => getenv('BACKUP_TEST_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
    ];
}

/** Drops the tables a backup test creates on the backup connection. */
function dropBackupTestTables(): void
{
    $schema = Schema::connection('backup_test');
    $schema->disableForeignKeyConstraints();

    foreach (['bk_photos', 'bk_albums', 'bk_pivot', 'bk_added', 'bk_other_app', 'cache'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->enableForeignKeyConstraints();
}

/**
 * Runs a whole backup, one portion per call.
 *
 * @param  list<string>  $paths
 */
function runBackup(DatabaseBackup $backup, array $paths): void
{
    while (! $backup->backup(microtime(true) - 1, $paths)) {
        // One portion per call, as on a host with a short time limit.
    }
}

/**
 * Runs a whole restore, one portion per call.
 *
 * @param  list<string>  $paths
 */
function runRestore(DatabaseBackup $backup, array $paths = []): void
{
    while (! $backup->restore(microtime(true) - 1, $paths)) {
        // One portion per call.
    }
}

/**
 * Every core text key used in the code: literal arguments of __(), trans(),
 * trans_choice() and @lang() in app/, resources/views, themes/ * /views and
 * routes/. Namespaced keys (plugins, themes, Filament) and framework groups
 * (auth.*, validation.*, ...) are left out.
 *
 * @return array<string, string> key => first file it appears in
 */
function coreTranslationKeys(): array
{
    $files = collect([app_path(), resource_path('views'), base_path('routes'), ...glob(base_path('themes/*/views')) ?: []])
        ->flatMap(fn (string $dir) => File::allFiles($dir))
        ->filter(fn (SplFileInfo $file) => str_ends_with($file->getFilename(), '.php'));

    $keys = [];
    foreach ($files as $file) {
        preg_match_all('/(?:__|trans_choice|trans|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = ($match[2] ?? '') !== '' ? stripcslashes($match[2]) : str_replace("\\'", "'", $match[1]);

            if (! str_contains($key, '::') && ! preg_match('/^(auth|passwords|pagination|validation)\.[\w.]+$/', $key)) {
                $keys[$key] ??= $file->getRelativePathname();
            }
        }
    }

    return $keys;
}

/** @return list<string> the :placeholders in a text, sorted */
function placeholders(string $text): array
{
    preg_match_all('/:([a-z_]+)/i', $text, $matches);

    return collect($matches[1])->unique()->sort()->values()->all();
}

/**
 * Every German text the app knows (core JSON plus theme and plugin files), keyed by text.
 * Filament translates explicit labels once more (translateLabel()); those are German already.
 *
 * @return array<string, true>
 */
function germanTexts(): array
{
    $texts = array_values(json_decode(File::get(lang_path('de.json')), true));

    foreach ([...File::glob(base_path('themes/*/lang/de/*.php')), ...File::glob(base_path('plugins/*/lang/de/*.php'))] as $file) {
        array_push($texts, ...array_values(Arr::dot(require $file)));
    }

    return array_fill_keys(array_filter($texts, 'is_string'), true);
}

/** @return list<array{key: string, hidden: bool, size: string}> the signed-in user's layout without widget classes */
function layoutOf(User $user): array
{
    return array_map(fn (array $entry) => ['key' => $entry['key'], 'hidden' => $entry['hidden'], 'size' => $entry['size']], app(DashboardLayout::class)->entries($user->fresh()));
}

/** @return list<string> */
function layoutKeys(User $user): array
{
    return array_column(layoutOf($user), 'key');
}

/** @return array<string, string> the signed-in user's default arrangement, key => size */
function defaultsOf(User $user): array
{
    test()->actingAs($user);

    return collect(layoutOf($user))->mapWithKeys(fn (array $entry) => [$entry['key'] => $entry['size']])->all();
}

/** @return list<string> keys of the dashboard widgets the signed-in user sees */
function visibleWidgets(): array
{
    return collect(app(DashboardWidgets::class)->all())
        ->filter(fn (string $widget) => $widget::canView())
        ->keys()
        ->values()
        ->all();
}

/**
 * Runs the admin search like the field in the top bar does.
 *
 * @return array<string, list<GlobalSearchResult>>
 */
function adminSearch(string $query): array
{
    return Filament::getGlobalSearchProvider()->getResults($query)
        ->getCategories()
        ->map(fn ($results) => collect($results)->values()->all())
        ->all();
}

/** @return list<string> */
function searchUrls(string $query): array
{
    return collect(adminSearch($query))->flatten()->map(fn (GlobalSearchResult $result) => $result->url)->all();
}

/** @return array<string, list<string>> the entries of the admin menu, by group */
function adminMenu(): array
{
    return collect(Filament::getNavigation())
        ->mapWithKeys(fn (NavigationGroup $group): array => [
            (string) $group->getLabel() => collect($group->getItems())
                ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
                ->values()
                ->all(),
        ])
        ->all();
}
