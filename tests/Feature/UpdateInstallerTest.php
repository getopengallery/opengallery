<?php

use App\Enums\Role;
use App\Enums\UpdateChannel;
use App\Filament\Clusters\Settings\Pages\UpdateSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\SiteSettings;
use App\Support\UpdateCheck;
use App\Support\Updates\ReleaseManifest;
use App\Support\Updates\ReleasePlan;
use App\Support\Updates\UpdateFailed;
use App\Support\Updates\UpdateInstaller;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../../resources/updater/runner.php';

/**
 * A zip with the files every release needs, plus $files.
 *
 * @param  array<string, string>  $files  path inside the release => contents
 * @return array{path: string, files: int, bytes: int}
 */
function releaseZip(string $folder, string $version, array $files = []): array
{
    $contents = [
        'VERSION' => "{$version}\n",
        'artisan' => '<?php',
        'bootstrap/app.php' => '<?php',
        'public/index.php' => '<?php',
        'public/build/manifest.json' => '{}',
        'vendor/autoload.php' => '<?php',
        ...$files,
    ];
    $path = "{$folder}/opengallery-v{$version}.zip";
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($contents as $name => $body) {
        $zip->addFromString("opengallery/{$name}", $body);
    }

    $zip->close();

    return ['path' => $path, 'files' => count($contents), 'bytes' => array_sum(array_map('strlen', $contents))];
}

/** @param  array{path: string, files: int, bytes: int}  $zip */
function signedManifest(array $zip, string $version, string $secret, array $overrides = []): ReleaseManifest
{
    return (new ReleaseManifest(...[
        'version' => $version,
        'channel' => ReleaseManifest::channelOf($version),
        'file' => "opengallery-v{$version}.zip",
        'size' => (int) filesize($zip['path']),
        'unpackedSize' => $zip['bytes'],
        'files' => $zip['files'],
        'sha256' => hash_file('sha256', $zip['path']),
        'requiresPhp' => '8.3.0',
        ...$overrides,
    ]))->signedWith($secret);
}

function fakeReleaseHost(string $zipPath, ReleaseManifest $manifest): void
{
    $bytes = (string) file_get_contents($zipPath);

    Http::fake(function (Request $request) use ($bytes, $manifest) {
        if (str_ends_with($request->url(), '.update.json')) {
            return Http::response(json_encode($manifest->toArray()));
        }

        preg_match('/bytes=(\d+)-(\d+)/', (string) ($request->header('Range')[0] ?? ''), $range);

        return Http::response(substr($bytes, (int) $range[1], (int) $range[2] - (int) $range[1] + 1), 206);
    });
}

function offerRelease(string $version, UpdateChannel $channel = UpdateChannel::Stable): void
{
    Setting::put(SiteSettings::UPDATE_CHECKS, true);
    Setting::put(SiteSettings::UPDATE_CHANNEL, $channel->value);
    Setting::put(UpdateCheck::STATUS, [
        'attempted_at' => now()->getTimestamp(),
        'checked_at' => now()->getTimestamp(),
        'failed' => false,
        $channel->value => ['version' => $version, 'url' => "https://github.com/getopengallery/opengallery/releases/tag/v{$version}"],
    ]);
}

function updateSuperAdmin(): User
{
    return tap(User::factory()->create())->assignRole(Role::SuperAdmin->value);
}

function prepareRelease(User $user): void
{
    UpdateInstaller::start($user);

    do {
        $progress = UpdateInstaller::advance();
    } while ($progress['phase'] !== UpdateInstaller::PHASE_READY);
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->root = storage_path('framework/testing/update-'.Str::lower(Str::random(10)));
    File::ensureDirectoryExists($this->root);
    $this->entry = "{$this->root}/maintenance.php";
    UpdateInstaller::usePaths("{$this->root}/updates", $this->entry);

    $this->versionFile = "{$this->root}/VERSION";
    File::put($this->versionFile, '1.0.0');
    AppVersion::useFile($this->versionFile);

    $stable = sodium_crypto_sign_keypair();
    $dev = sodium_crypto_sign_keypair();
    $this->stableSecret = sodium_crypto_sign_secretkey($stable);
    $this->devSecret = sodium_crypto_sign_secretkey($dev);
    config([
        'gallery.update_repository' => 'getopengallery/opengallery',
        'gallery.update_install' => true,
        'gallery.update_download_url' => 'https://github.com',
        'gallery.update_keys' => [
            'stable' => base64_encode(sodium_crypto_sign_publickey($stable)),
            'dev' => base64_encode(sodium_crypto_sign_publickey($dev)),
        ],
    ]);
});

afterEach(function () {
    UpdateInstaller::usePaths(null, null);
    AppVersion::useFile(null);
    File::deleteDirectory($this->root);
});

describe('release signatures', function () {
    it('accepts a release signed with the key of its channel', function () {
        $zip = releaseZip($this->root, '1.1.0');

        signedManifest($zip, '1.1.0', $this->stableSecret)->verify(UpdateChannel::Stable);
    })->throwsNoExceptions();

    it('rejects a release whose signed details were changed', function (string $field, mixed $value) {
        $zip = releaseZip($this->root, '1.1.0');
        $data = [...signedManifest($zip, '1.1.0', $this->stableSecret)->toArray(), $field => $value];

        if ($field === 'version') {
            $data['file'] = "opengallery-v{$value}.zip";
        }

        ReleaseManifest::fromArray($data)->verify(UpdateChannel::Stable);
    })->with([
        'version' => ['version', '9.9.9'],
        'checksum' => ['sha256', str_repeat('a', 64)],
        'size' => ['size', 1234],
        'required version' => ['requires_from', '0.1.0'],
    ])->throws(UpdateFailed::class, 'signature');

    it('does not let a stable site install a test version', function () {
        $zip = releaseZip($this->root, '1.1.0-dev.1');

        signedManifest($zip, '1.1.0-dev.1', $this->devSecret)->verify(UpdateChannel::Stable);
    })->throws(UpdateFailed::class, 'signature');

    it('does not accept the dev key for a stable release', function () {
        $zip = releaseZip($this->root, '1.1.0');

        signedManifest($zip, '1.1.0', $this->devSecret)->verify(UpdateChannel::Dev);
    })->throws(UpdateFailed::class, 'signature');

    it('refuses to verify without a configured key', function () {
        $zip = releaseZip($this->root, '1.1.0');
        $manifest = signedManifest($zip, '1.1.0', $this->stableSecret);
        config(['gallery.update_keys.stable' => '']);

        $manifest->verify(UpdateChannel::Stable);
    })->throws(UpdateFailed::class, 'signature');

    it('rejects manifests with unsafe values', function (array $change) {
        $zip = releaseZip($this->root, '1.1.0');

        ReleaseManifest::fromArray([...signedManifest($zip, '1.1.0', $this->stableSecret)->toArray(), ...$change]);
    })->with([
        'file outside' => [['file' => '../../evil.zip']],
        'other file name' => [['file' => 'opengallery-v9.0.0.zip']],
        'channel mismatch' => [['channel' => 'dev']],
        'bad checksum' => [['sha256' => 'abc']],
        'no format' => [['format' => 99]],
    ])->throws(UpdateFailed::class);
});

describe('preparing an update', function () {
    it('downloads in portions, verifies and unpacks while the site stays online', function () {
        $body = random_bytes(5 * 1024 * 1024);
        $zip = releaseZip($this->root, '1.1.0', ['vendor/large.bin' => $body]);
        fakeReleaseHost($zip['path'], signedManifest($zip, '1.1.0', $this->stableSecret));
        offerRelease('1.1.0');

        prepareRelease(updateSuperAdmin());

        expect(UpdateInstaller::preparing()['phase'])->toBe(UpdateInstaller::PHASE_READY)
            ->and(file_get_contents("{$this->root}/updates/staging/vendor/large.bin"))->toBe($body)
            ->and(File::exists($this->entry))->toBeFalse();
        Http::assertSent(fn (Request $request) => str_contains((string) ($request->header('Range')[0] ?? ''), 'bytes=4194304-'));
    });

    it('stops and removes everything when the checksum does not match', function () {
        $zip = releaseZip($this->root, '1.1.0');
        $manifest = signedManifest($zip, '1.1.0', $this->stableSecret, ['sha256' => str_repeat('a', 64)]);
        fakeReleaseHost($zip['path'], $manifest);
        offerRelease('1.1.0');
        UpdateInstaller::start(updateSuperAdmin());

        expect(fn () => UpdateInstaller::advance())->toThrow(UpdateFailed::class, 'not valid')
            ->and(File::exists("{$this->root}/updates"))->toBeFalse();
    });

    it('rejects a signed zip that contains a path out of its folder', function () {
        $zip = releaseZip($this->root, '1.1.0');
        $archive = new ZipArchive;
        $archive->open($zip['path']);
        $archive->addFromString('opengallery/../../evil.php', 'x');
        $archive->close();
        $zip = ['path' => $zip['path'], 'files' => $zip['files'] + 1, 'bytes' => $zip['bytes'] + 1];
        fakeReleaseHost($zip['path'], signedManifest($zip, '1.1.0', $this->stableSecret));
        offerRelease('1.1.0');
        UpdateInstaller::start(updateSuperAdmin());

        expect(fn () => UpdateInstaller::advance())->toThrow(UpdateFailed::class, 'not valid')
            ->and(File::exists(dirname($this->root).'/evil.php'))->toBeFalse();
    });

    it('rejects a signed zip that contains a symbolic link', function () {
        $zip = releaseZip($this->root, '1.1.0', ['public/link' => '/etc/passwd']);
        $archive = new ZipArchive;
        $archive->open($zip['path']);
        $archive->setExternalAttributesName('opengallery/public/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $archive->close();
        fakeReleaseHost($zip['path'], signedManifest($zip, '1.1.0', $this->stableSecret));
        offerRelease('1.1.0');
        UpdateInstaller::start(updateSuperAdmin());

        UpdateInstaller::advance();
    })->throws(UpdateFailed::class, 'not valid');

    it('does not start a release with a bad signature', function () {
        $zip = releaseZip($this->root, '1.1.0');
        fakeReleaseHost($zip['path'], signedManifest($zip, '1.1.0', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        offerRelease('1.1.0');

        expect(fn () => UpdateInstaller::start(updateSuperAdmin()))->toThrow(UpdateFailed::class, 'signature')
            ->and(UpdateInstaller::isRunning())->toBeFalse();
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '.zip'));
    });

    it('asks for a manual install when a release has no signed manifest', function () {
        Http::fake(['*' => Http::response('', 404)]);
        offerRelease('1.1.0');

        UpdateInstaller::start(updateSuperAdmin());
    })->throws(UpdateFailed::class, 'by hand');

    it('refuses a release this server or version cannot take', function (array $overrides, string $message) {
        $zip = releaseZip($this->root, '1.1.0');
        fakeReleaseHost($zip['path'], signedManifest($zip, '1.1.0', $this->stableSecret, $overrides));
        offerRelease('1.1.0');

        expect(fn () => UpdateInstaller::start(updateSuperAdmin()))->toThrow(UpdateFailed::class, $message);
    })->with([
        'newer PHP' => [['requiresPhp' => '99.0.0'], 'needs PHP'],
        'must update in between' => [['requiresFrom' => '1.0.5'], 'Update to version 1.0.5 first'],
    ]);

    it('does not start while the site is in maintenance mode', function () {
        File::put($this->entry, '<?php');
        $zip = releaseZip($this->root, '1.1.0');
        fakeReleaseHost($zip['path'], signedManifest($zip, '1.1.0', $this->stableSecret));
        offerRelease('1.1.0');

        UpdateInstaller::start(updateSuperAdmin());
    })->throws(UpdateFailed::class, 'maintenance');

    it('only lets super administrators install, and only while the server allows it', function (Role $role, bool $serverAllows, bool $allowed) {
        config(['gallery.update_install' => $serverAllows]);
        $user = tap(User::factory()->create())->assignRole($role->value);

        expect(UpdateInstaller::allowed($user))->toBe($allowed);
    })->with([
        'super admin' => [Role::SuperAdmin, true, true],
        'administrator' => [Role::Admin, true, false],
        'switched off on the server' => [Role::SuperAdmin, false, false],
    ]);
});

describe('handing over to the updater', function () {
    it('goes offline through the maintenance file and keeps only a hash of the key', function () {
        $zip = releaseZip($this->root, '1.1.0');
        fakeReleaseHost($zip['path'], signedManifest($zip, '1.1.0', $this->stableSecret));
        offerRelease('1.1.0');
        $admin = updateSuperAdmin();
        prepareRelease($admin);

        $token = UpdateInstaller::handOver($admin);
        $state = json_decode((string) file_get_contents("{$this->root}/updates/state.json"), true);

        expect(File::get($this->entry))->toContain('OpenGalleryUpdater::handle')
            ->and(File::exists("{$this->root}/updates/runner.php"))->toBeTrue()
            ->and($state['token_hash'])->toBe(hash('sha256', $token))
            ->and(File::get("{$this->root}/updates/state.json"))->not->toContain($token);
    });

    it('refuses another administrator than the one who started', function () {
        $zip = releaseZip($this->root, '1.1.0');
        fakeReleaseHost($zip['path'], signedManifest($zip, '1.1.0', $this->stableSecret));
        offerRelease('1.1.0');
        prepareRelease(updateSuperAdmin());

        expect(fn () => UpdateInstaller::handOver(updateSuperAdmin()))->toThrow(UpdateFailed::class)
            ->and(File::exists($this->entry))->toBeFalse();
    });
});

describe('what an update replaces', function () {
    beforeEach(function () {
        $this->base = "{$this->root}/site";
        $this->staging = "{$this->root}/staging";

        foreach ([
            '.env' => 'SECRET=1',
            'VERSION' => '1.0.0',
            'app/Old.php' => 'old',
            'storage/app/photo.jpg' => 'photo',
            'bootstrap/app.php' => 'old',
            'bootstrap/cache/config.php' => 'cache',
            'database/database.sqlite' => 'data',
            'database/migrations/old.php' => 'old',
            'public/index.php' => 'old',
            'public/.htaccess' => 'custom rules',
            'public/my-own-file.txt' => 'mine',
            'plugins/third-party/plugin.json' => '{"name":"x","version":"1.0.0"}',
            'themes/default/theme.json' => '{"name":"x","version":"1.0.0"}',
            'themes/scrapbook/theme.json' => '{"name":"x","version":"3.0.0"}',
        ] as $path => $body) {
            File::ensureDirectoryExists(dirname("{$this->base}/{$path}"));
            File::put("{$this->base}/{$path}", $body);
        }

        foreach ([
            '.env' => 'WRONG=1',
            'VERSION' => '1.1.0',
            'app/New.php' => 'new',
            'storage/app/photo.jpg' => 'WRONG',
            'bootstrap/app.php' => 'new',
            'bootstrap/cache/packages.php' => 'WRONG',
            'database/migrations/new.php' => 'new',
            'public/index.php' => 'new',
            'public/.htaccess' => 'release rules',
            'public/build/manifest.json' => 'new',
            'themes/default/theme.json' => '{"name":"x","version":"2.0.0"}',
            'themes/scrapbook/theme.json' => '{"name":"x","version":"2.0.0"}',
            'plugins/identity-providers/plugin.json' => '{"name":"x","version":"1.0.0"}',
        ] as $path => $body) {
            File::ensureDirectoryExists(dirname("{$this->staging}/{$path}"));
            File::put("{$this->staging}/{$path}", $body);
        }

        File::ensureDirectoryExists("{$this->root}/work");
        File::put("{$this->root}/work/state.json", json_encode([
            'phase' => 'swap',
            'token_hash' => hash('sha256', 'token'),
            'version' => '1.1.0',
            'from' => '1.0.0',
            'base' => $this->base,
            'staging' => $this->staging,
            'entry_file' => "{$this->root}/entry.php",
            'plan' => ReleasePlan::build($this->staging, $this->base, "{$this->root}/work/previous"),
            'swapped' => 0,
            'migrated' => false,
            'return_url' => 'https://example.test/admin',
            'texts' => [],
        ]));
        File::put("{$this->root}/entry.php", '<?php');
        $this->before = siteFiles($this->base);
    });

    it('replaces the code and keeps everything that belongs to this gallery', function () {
        OpenGalleryUpdater::forDirectory("{$this->root}/work")->swap();

        expect(File::get("{$this->base}/VERSION"))->toBe('1.1.0')
            ->and(File::exists("{$this->base}/app/New.php"))->toBeTrue()
            ->and(File::exists("{$this->base}/app/Old.php"))->toBeFalse()
            ->and(File::exists("{$this->base}/database/migrations/old.php"))->toBeFalse()
            ->and(File::get("{$this->base}/public/index.php"))->toBe('new')
            ->and(File::get("{$this->base}/themes/default/theme.json"))->toContain('2.0.0')
            ->and(File::exists("{$this->base}/plugins/identity-providers/plugin.json"))->toBeTrue()
            ->and(File::get("{$this->base}/.env"))->toBe('SECRET=1')
            ->and(File::get("{$this->base}/storage/app/photo.jpg"))->toBe('photo')
            ->and(File::get("{$this->base}/database/database.sqlite"))->toBe('data')
            ->and(File::get("{$this->base}/public/.htaccess"))->toBe('custom rules')
            ->and(File::get("{$this->base}/public/my-own-file.txt"))->toBe('mine')
            ->and(File::exists("{$this->base}/plugins/third-party/plugin.json"))->toBeTrue()
            ->and(File::get("{$this->base}/themes/scrapbook/theme.json"))->toContain('3.0.0');
    });

    it('puts every file back on rollback', function () {
        $updater = OpenGalleryUpdater::forDirectory("{$this->root}/work");
        $updater->swap();

        $updater->rollback();

        $after = array_diff_key(siteFiles($this->base), ['bootstrap/cache/config.php' => true]);
        expect($after)->toBe(array_diff_key($this->before, ['bootstrap/cache/config.php' => true]));
    });

    it('can undo a swap that was interrupted halfway', function () {
        $state = json_decode(File::get("{$this->root}/work/state.json"), true);
        $middle = (int) floor(count($state['plan']) / 2);
        $updater = OpenGalleryUpdater::forDirectory("{$this->root}/work");
        $updater->swap();
        // Back to the moment the middle item's old copy had moved, but its new copy had not.
        $item = $state['plan'][$middle];
        rename($item['target'], $item['source']);
        $interrupted = [...json_decode(File::get("{$this->root}/work/state.json"), true), 'swapped' => count($state['plan'])];
        File::put("{$this->root}/work/state.json", json_encode($interrupted));

        OpenGalleryUpdater::forDirectory("{$this->root}/work")->rollback();

        $after = array_diff_key(siteFiles($this->base), ['bootstrap/cache/config.php' => true]);
        expect($after)->toBe(array_diff_key($this->before, ['bootstrap/cache/config.php' => true]));
    });

    it('finishes a resumed swap without losing a file', function () {
        $state = json_decode(File::get("{$this->root}/work/state.json"), true);
        $item = $state['plan'][0];
        File::ensureDirectoryExists(dirname($item['previous']));
        if ($item['existed']) {
            rename($item['target'], $item['previous']);
        }
        File::put("{$this->root}/work/state.json", json_encode([...$state, 'swapped' => 1]));

        OpenGalleryUpdater::forDirectory("{$this->root}/work")->swap();

        expect(file_exists($item['target']))->toBeTrue()
            ->and(file_exists($item['source']))->toBeFalse();
    });

    it('rolls back after a failure past the swap, and stops if the way back fails too', function () {
        $updater = OpenGalleryUpdater::forDirectory("{$this->root}/work");

        $updater->fail('migration failed');
        expect($updater->currentState()['phase'])->toBe('rollback')
            ->and($updater->currentState()['error'])->toBe('migration failed');

        $updater->fail('rollback failed');
        expect($updater->currentState()['phase'])->toBe('stuck')
            ->and($updater->currentState()['stuck_phase'])->toBe('rollback')
            ->and($updater->currentState()['error'])->toBe('migration failed')
            ->and(File::exists("{$this->root}/entry.php"))->toBeTrue();
    });

    it('opens the site again and records the result when finished', function () {
        OpenGalleryUpdater::forDirectory("{$this->root}/work")->finish(false);
        $result = json_decode(File::get("{$this->root}/update-result.json"), true);

        expect(File::exists("{$this->root}/entry.php"))->toBeFalse()
            ->and(File::exists("{$this->root}/work"))->toBeFalse()
            ->and($result['status'])->toBe('restored')
            ->and($result['version'])->toBe('1.1.0');
    });
});

describe('the settings page', function () {
    it('offers the install only to super administrators', function (Role $role, bool $offered) {
        $this->actingAs(tap(User::factory()->create())->assignRole($role->value));
        offerRelease('1.1.0');

        $page = Livewire::test(UpdateSettings::class);

        $offered ? $page->assertSee('Install update') : $page->assertDontSee('Install update');
    })->with([
        'super admin' => [Role::SuperAdmin, true],
        'administrator' => [Role::Admin, false],
    ]);

    it('forbids the install steps for other administrators', function (string $method) {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

        Livewire::test(UpdateSettings::class)->call($method, ...($method === 'startInstall' ? ['password'] : []))->assertForbidden();
    })->with(['startInstall', 'advanceInstall', 'handOverInstall']);

    it('needs the current password and limits wrong attempts', function () {
        $admin = updateSuperAdmin();
        $this->actingAs($admin);
        offerRelease('1.1.0');
        Http::fake();
        $page = Livewire::test(UpdateSettings::class);

        foreach (range(1, 5) as $attempt) {
            $page->call('startInstall', 'wrong password');
        }
        Notification::assertNotified('The password is not correct.');

        $page->call('startInstall', 'password');

        Notification::assertNotified('Too many attempts. Try again in a minute.');
        Http::assertNothingSent();
        expect(UpdateInstaller::isRunning())->toBeFalse();
    });

    it('shows how the last update ended', function (string $status, string $text) {
        $this->actingAs(updateSuperAdmin());
        File::put("{$this->root}/update-result.json", json_encode([
            'status' => $status, 'version' => '1.1.0', 'from' => '1.0.0', 'at' => now()->getTimestamp(), 'detail' => null,
        ]));

        Livewire::test(UpdateSettings::class)->assertSee($text);
    })->with([
        'updated' => ['updated', 'Updated to version 1.1.0'],
        'restored' => ['restored', 'The update to version 1.1.0 failed'],
    ]);
});

/** @return array<string, string> relative path => contents of every file under $base */
function siteFiles(string $base): array
{
    $files = [];

    foreach (File::allFiles($base, true) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    ksort($files);

    return $files;
}
