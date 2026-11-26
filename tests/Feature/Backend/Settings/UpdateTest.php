<?php

use App\Enums\Role;
use App\Enums\UpdateChannel;
use App\Filament\Clusters\Settings\Pages\UpdateSettings;
use App\Support\AppVersion;
use App\Support\SiteSettings;
use App\Support\UpdateCheck;
use App\Support\Updates\UpdateInstaller;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->root = storage_path('framework/testing/update-'.Str::lower(Str::random(10)));
    File::ensureDirectoryExists($this->root);
    UpdateInstaller::usePaths("{$this->root}/updates", "{$this->root}/maintenance.php");

    $this->versionFile = "{$this->root}/VERSION";
    File::put($this->versionFile, '1.0.0');
    AppVersion::useFile($this->versionFile);

    config([
        'gallery.update_repository' => 'getopengallery/opengallery',
        'gallery.update_install' => true,
    ]);
});

afterEach(function () {
    UpdateInstaller::usePaths(null, null);
    AppVersion::useFile(null);
    File::deleteDirectory($this->root);
});

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin));

    it('saves the choice and checks right away when turned on', function () {
        Http::fake([UPDATE_API => Http::response([githubRelease('v1.2.0-dev.1', prerelease: true)])]);

        Livewire::test(UpdateSettings::class)
            ->set('data.update_checks', true)
            ->set('data.update_channel', UpdateChannel::Dev->value)
            ->call('save')
            ->assertHasNoErrors();

        expect(SiteSettings::updateChecksEnabled())->toBeTrue()
            ->and(SiteSettings::updateChannel())->toBe(UpdateChannel::Dev)
            ->and(UpdateCheck::available()['version'])->toBe('1.2.0-dev.1');
        Http::assertSentCount(1);
    });

    it('sends no request when saved with checks off', function () {
        Http::fake();

        Livewire::test(UpdateSettings::class)
            ->set('data.update_checks', false)
            ->call('save')
            ->assertHasNoErrors();

        expect(SiteSettings::updateChecksEnabled())->toBeFalse();
        Http::assertNothingSent();
    });

    it('shows the update with a link to its release', function () {
        turnOnUpdateChecks();
        Http::fake([UPDATE_API => Http::response([githubRelease('v1.1.0')])]);
        UpdateCheck::run();

        Livewire::test(UpdateSettings::class)
            ->assertSeeHtmlInOrder(['Installed version:', 'href="https://github.com/getopengallery/opengallery/releases/tag/v1.0.0"', '1.0.0</a>'])
            ->assertSee('Version 1.1.0 is available')
            ->assertSee('https://github.com/getopengallery/opengallery/releases/tag/v1.1.0')
            ->assertDontSee('evil.example')
            ->assertDontSee('alert(1)');
    });

    it('shows the installed version as plain text when it has no release page', function (string $version) {
        File::put($this->versionFile, $version);

        Livewire::test(UpdateSettings::class)
            ->assertSee("Installed version: {$version}")
            ->assertDontSeeHtml('/releases/tag/');
    })->with(['0.2.0-dev', 'dev']);

    it('reports the result of a manual check', function (array $response, int $status, string $title) {
        turnOnUpdateChecks();
        Http::fake([UPDATE_API => Http::response($response, $status)]);

        Livewire::test(UpdateSettings::class)->call('checkNow');

        Notification::assertNotified($title);
    })->with([
        'update' => [[['tag_name' => 'v1.1.0', 'prerelease' => false, 'draft' => false]], 200, 'Version 1.1.0 is available'],
        'current' => [[['tag_name' => 'v1.0.0', 'prerelease' => false, 'draft' => false]], 200, 'This gallery is up to date'],
        'failure' => [['message' => 'rate limit'], 403, 'The update check failed. Try again later.'],
    ]);

    it('refuses a manual check while checks are off', function () {
        Http::fake();

        Livewire::test(UpdateSettings::class)->call('checkNow')->assertForbidden();

        Http::assertNothingSent();
    });

    it('forbids the install steps', function (string $method) {
        Livewire::test(UpdateSettings::class)->call($method, ...($method === 'startInstall' ? ['password'] : []))->assertForbidden();
    })->with(['startInstall', 'advanceInstall', 'handOverInstall']);
});

it('offers the install only to super administrators', function (Role $role, bool $offered) {
    actingAsRole($role);
    offerRelease('1.1.0');

    $page = Livewire::test(UpdateSettings::class);

    $offered ? $page->assertSee('Install update') : $page->assertDontSee('Install update');
})->with([
    'super admin' => [Role::SuperAdmin, true],
    'administrator' => [Role::Admin, false],
]);

describe('as a super admin', function () {
    beforeEach(fn () => actingAsRole(Role::SuperAdmin));

    it('needs the current password and limits wrong attempts', function () {
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
        File::put("{$this->root}/update-result.json", json_encode([
            'status' => $status, 'version' => '1.1.0', 'from' => '1.0.0', 'at' => now()->getTimestamp(), 'detail' => null,
        ]));

        Livewire::test(UpdateSettings::class)->assertSee($text);
    })->with([
        'updated' => ['updated', 'Updated to version 1.1.0'],
        'restored' => ['restored', 'The update to version 1.1.0 failed'],
    ]);
});
