<?php

use App\Extensions\PluginManager;
use App\Support\ProductionCaches;
use BladeUI\Icons\IconsManifest;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
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

it('decides what to drop and what to build', function (bool $built, bool $fresh, bool $wanted, array $expected) {
    expect(ProductionCaches::plan($built, $fresh, $wanted))->toBe($expected);
})->with([
    'missing on a live site' => [false, true, true, ['clear' => false, 'build' => true]],
    'up to date' => [true, true, true, ['clear' => false, 'build' => false]],
    'outdated on a live site' => [true, false, true, ['clear' => true, 'build' => true]],
    'outdated on a development site' => [true, false, false, ['clear' => true, 'build' => false]],
    'missing on a development site' => [false, true, false, ['clear' => false, 'build' => false]],
]);

describe('on a live site', function () {
    beforeEach(fn () => app()['env'] = 'production');

    it('builds missing caches after the response', function () {
        $folder = temporaryCacheFolder();
        $commands = recordCacheCommands();

        $this->get(route('home'))->assertOk();

        expect($commands->getArrayCopy())->toBe(['config:cache', 'event:cache', 'route:cache']);
        expect($folder.'/filament/panels/admin.php')->toBeFile();
    });

    it('drops caches made from older code at once and builds them anew', function () {
        temporaryCacheFolder();
        cachesBuilt(outdated: true);
        $commands = recordCacheCommands();

        $this->get(route('home'))->assertOk();

        expect($commands->getArrayCopy())->toBe(['config:clear', 'event:clear', 'route:clear', 'config:cache', 'event:cache', 'route:cache']);
    });

    it('rebuilds from the current admin pages when Filament still has the list of an older version', function (bool $outdated) {
        $folder = temporaryCacheFolder();
        $list = $folder.'/filament/panels/admin.php';
        Filament::getDefaultPanel()->cacheComponents();

        if ($outdated) {
            cachesBuilt(outdated: true);
        }

        $listPresentDuringBuild = new ArrayObject;
        $this->mock(Kernel::class, fn (MockInterface $mock) => $mock->shouldReceive('call')
            ->andReturnUsing(function (string $command) use ($list, $listPresentDuringBuild) {
                $listPresentDuringBuild[$command] = is_file($list);

                return 0;
            }));

        $this->get(route('home'))->assertOk();

        expect($listPresentDuringBuild['route:cache'])->toBeFalse()
            ->and($list)->not->toBeFile();
    })->with([
        'after a one-click update' => false,
        'after files were replaced by hand' => true,
    ]);

    it('does not write the old list back from a request that loaded it before it was dropped', function () {
        $folder = temporaryCacheFolder();
        $list = $folder.'/filament/panels/admin.php';
        Filament::getDefaultPanel()->cacheComponents();
        unlink($list);
        cachesBuilt();
        recordCacheCommands();

        $this->get(route('home'))->assertOk();

        expect($list)->not->toBeFile();
    });

    it('leaves caches that match the code alone', function () {
        $folder = temporaryCacheFolder();
        cachesBuilt();
        file_put_contents($folder.'/filament/panels/admin.php', '<?php return [];');
        $commands = recordCacheCommands();

        $this->get(route('home'))->assertOk();

        expect($commands->getArrayCopy())->toBe([]);
    });

    it('tries again at most once an hour while building fails, and says so in Site health', function () {
        temporaryCacheFolder();
        $commands = recordCacheCommands(exitCode: 1);

        $this->get(route('home'));
        $this->get(route('home'));

        expect(array_count_values($commands->getArrayCopy())['config:cache'])->toBe(1);
        expect(ProductionCaches::failing())->toBeTrue();
    });

    it('rebuilds the caches when a plugin is enabled, the admin\'s on the next request', function () {
        $folder = temporaryCacheFolder();
        cachesBuilt();
        $commands = recordCacheCommands();

        app(PluginManager::class)->enable('example');
        app()->terminate();

        expect($commands->getArrayCopy())->toBe(['config:clear', 'event:clear', 'route:clear', 'config:cache', 'event:cache', 'route:cache']);
        expect($folder.'/filament/panels/admin.php')->not->toBeFile();
    });
});

it('never writes stored settings or theme folders into the configuration cache', function () {
    $folder = temporaryCacheFolder();
    putenv('APP_CONFIG_CACHE='.$folder.'/config.php');
    putenv('APP_ROUTES_CACHE='.$folder.'/routes.php');
    putenv('APP_EVENTS_CACHE='.$folder.'/events.php');
    $this->beforeApplicationDestroyed(fn () => array_map(putenv(...), ['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE']));

    $built = ProductionCaches::build(withAdmin: false);

    $config = require $folder.'/config.php';
    expect($built)->toBeTrue();
    expect(data_get($config, 'gallery.env_defaults'))->toBeNull();
    expect(implode('|', $config['view']['paths']))->not->toContain('themes');
});
