<?php

use App\Enums\BackgroundJobMode;
use App\Enums\Role;
use App\Enums\UpdateChannel;
use App\Filament\Clusters\Settings\Pages\UpdateSettings;
use App\Filament\Widgets\SiteHealth;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\BackgroundJobs;
use App\Support\SiteSettings;
use App\Support\UpdateCheck;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const UPDATE_API = 'api.github.com/repos/getopengallery/opengallery/releases*';

/** @return array<string, mixed> a release as the GitHub API returns it */
function githubRelease(string $tag, bool $prerelease = false, bool $draft = false): array
{
    return [
        'tag_name' => $tag,
        'prerelease' => $prerelease,
        'draft' => $draft,
        'name' => '<script>alert(1)</script>',
        'html_url' => 'https://evil.example/download',
    ];
}

function turnOnUpdateChecks(UpdateChannel $channel = UpdateChannel::Stable): void
{
    Setting::put(SiteSettings::UPDATE_CHECKS, true);
    Setting::put(SiteSettings::UPDATE_CHANNEL, $channel->value);
}

function updateAdmin(): User
{
    return tap(User::factory()->create())->assignRole(Role::Admin->value);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['gallery.update_repository' => 'getopengallery/opengallery']);
    $this->versionFile = tempnam(sys_get_temp_dir(), 'og-version-');
    File::put($this->versionFile, '1.0.0');
    AppVersion::useFile($this->versionFile);
});

afterEach(function () {
    AppVersion::useFile(null);
    File::delete($this->versionFile);
});

it('reports the newest release of the chosen channel', function (UpdateChannel $channel, string $expected) {
    turnOnUpdateChecks($channel);
    Http::fake([UPDATE_API => Http::response([
        githubRelease('v1.2.0-dev.12', prerelease: true),
        githubRelease('v1.2.0-dev.2', prerelease: true),
        githubRelease('v1.1.0'),
        githubRelease('v1.0.1'),
    ])]);

    expect(UpdateCheck::run())->toBeTrue()
        ->and(UpdateCheck::available())->toBe([
            'version' => $expected,
            'url' => "https://github.com/getopengallery/opengallery/releases/tag/v{$expected}",
        ]);
})->with([
    'stable' => [UpdateChannel::Stable, '1.1.0'],
    'dev' => [UpdateChannel::Dev, '1.2.0-dev.12'],
]);

it('offers the finished version on the dev channel once it is newer than the test versions', function () {
    turnOnUpdateChecks(UpdateChannel::Dev);
    Http::fake([UPDATE_API => Http::response([
        githubRelease('v1.2.0'),
        githubRelease('v1.2.0-rc.1', prerelease: true),
    ])]);

    UpdateCheck::run();

    expect(UpdateCheck::available()['version'])->toBe('1.2.0');
});

it('moves an installed test version on to its finished release', function () {
    File::put($this->versionFile, '1.2.0-dev.4');
    turnOnUpdateChecks();
    Http::fake([UPDATE_API => Http::response([githubRelease('v1.2.0')])]);

    UpdateCheck::run();

    expect(UpdateCheck::available()['version'])->toBe('1.2.0');
});

it('ignores drafts, versions marked as prereleases and tags that are no version', function () {
    turnOnUpdateChecks();
    Http::fake([UPDATE_API => Http::response([
        githubRelease('v9.0.0', draft: true),
        githubRelease('v8.0.0', prerelease: true),
        githubRelease('v7.0.0-beta.1'),
        githubRelease('nightly'),
        githubRelease('v6.0.0/../../evil'),
        'not a release',
    ])]);

    expect(UpdateCheck::run())->toBeTrue()
        ->and(UpdateCheck::available())->toBeNull();
});

it('tells nothing when this copy is current, newer or has no comparable version', function (string $installed) {
    File::put($this->versionFile, $installed);
    turnOnUpdateChecks();
    Http::fake([UPDATE_API => Http::response([githubRelease('v1.0.0')])]);

    UpdateCheck::run();

    expect(UpdateCheck::available())->toBeNull();
})->with(['same' => '1.0.0', 'newer' => '1.5.0', 'unknown' => 'dev']);

it('sends no request while checks are off or no valid repository is set', function (bool $enabled, string $repository) {
    Setting::put(SiteSettings::UPDATE_CHECKS, $enabled);
    config(['gallery.update_repository' => $repository]);
    Http::fake();

    expect(UpdateCheck::run())->toBeFalse();

    Http::assertNothingSent();
})->with([
    'off' => [false, 'getopengallery/opengallery'],
    'empty repository' => [true, ''],
    'invalid repository' => [true, 'evil.example/a/b'],
    'path out of the repository' => [true, '../..'],
]);

it('sends nothing about the gallery', function () {
    config(['app.name' => 'Private family gallery', 'app.url' => 'https://family.example']);
    turnOnUpdateChecks();
    Http::fake([UPDATE_API => Http::response([])]);

    UpdateCheck::run();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/getopengallery/opengallery/releases?per_page=30'
        && $request->header('User-Agent') === ['OpenGallery-update-check']
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('Referer'));
});

it('keeps the last result when a check fails', function (Closure $failure) {
    $this->freezeTime();
    turnOnUpdateChecks();
    Http::fake([UPDATE_API => Http::sequence()
        ->push([githubRelease('v1.1.0')])
        ->pushResponse($failure())]);

    UpdateCheck::run();
    $this->travel(UpdateCheck::MIN_SECONDS)->seconds();

    expect(UpdateCheck::run())->toBeFalse()
        ->and(UpdateCheck::status()['failed'])->toBeTrue()
        ->and(UpdateCheck::available()['version'])->toBe('1.1.0');
})->with([
    'rate limit' => fn () => fn () => Http::response(['message' => 'rate limit'], 403),
    'server error' => fn () => fn () => Http::response('', 502),
    'not a list' => fn () => fn () => Http::response(['message' => 'moved']),
    'no connection' => fn () => fn () => Http::failedConnection(),
]);

it('asks at most once a minute', function () {
    $this->freezeTime();
    turnOnUpdateChecks();
    Http::fake([UPDATE_API => Http::response([githubRelease('v1.1.0')])]);

    UpdateCheck::run();
    expect(UpdateCheck::run())->toBeTrue();
    Http::assertSentCount(1);

    $this->travel(UpdateCheck::MIN_SECONDS)->seconds();
    UpdateCheck::run();
    Http::assertSentCount(2);
});

it('checks once a day in the background jobs', function () {
    $this->freezeTime();
    turnOnUpdateChecks();
    Http::fake([UPDATE_API => Http::response([githubRelease('v1.1.0')])]);

    BackgroundJobs::run(BackgroundJobMode::Cron);
    $this->travel(BackgroundJobs::MIN_SECONDS)->seconds();
    BackgroundJobs::run(BackgroundJobMode::Cron);
    Http::assertSentCount(1);

    $this->travel(1)->days();
    BackgroundJobs::run(BackgroundJobMode::Cron);
    Http::assertSentCount(2);
});

describe('the settings page', function () {
    it('is only open to people who manage settings', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Uploader->value));

        $this->get(UpdateSettings::getUrl())->assertForbidden();
    });

    it('saves the choice and checks right away when turned on', function () {
        $this->actingAs(updateAdmin());
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
        $this->actingAs(updateAdmin());
        Http::fake();

        Livewire::test(UpdateSettings::class)
            ->set('data.update_checks', false)
            ->call('save')
            ->assertHasNoErrors();

        expect(SiteSettings::updateChecksEnabled())->toBeFalse();
        Http::assertNothingSent();
    });

    it('shows the update with a link to its release', function () {
        $this->actingAs(updateAdmin());
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
        $this->actingAs(updateAdmin());
        File::put($this->versionFile, $version);

        Livewire::test(UpdateSettings::class)
            ->assertSee("Installed version: {$version}")
            ->assertDontSeeHtml('/releases/tag/');
    })->with(['0.2.0-dev', 'dev']);

    it('reports the result of a manual check', function (array $response, int $status, string $title) {
        $this->actingAs(updateAdmin());
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
        $this->actingAs(updateAdmin());
        Http::fake();

        Livewire::test(UpdateSettings::class)->call('checkNow')->assertForbidden();

        Http::assertNothingSent();
    });
});

describe('the notices in the admin', function () {
    beforeEach(function () {
        turnOnUpdateChecks();
        Http::fake([UPDATE_API => Http::response([githubRelease('v1.1.0')])]);
        UpdateCheck::run();
    });

    it('notes an update in site health', function () {
        $this->actingAs(updateAdmin());

        Livewire::test(SiteHealth::class)
            ->assertSee('Update available')
            ->assertSee('OpenGallery 1.1.0 is available. This gallery runs 1.0.0.')
            ->assertSee('System → Updates');
    });

    it('links to the update in the menu only for people who manage settings', function (Role $role, bool $visible) {
        $this->actingAs(tap(User::factory()->create())->assignRole($role->value));

        $response = $this->get('/admin')->assertOk();

        $visible ? $response->assertSee('Update to 1.1.0') : $response->assertDontSee('Update to 1.1.0');
    })->with([
        'administrator' => [Role::Admin, true],
        'uploader' => [Role::Uploader, false],
    ]);

    it('links the version in the menu to the updates page while update checks are on', function () {
        $this->actingAs(updateAdmin());

        $this->get('/admin')->assertOk()->assertSeeHtml('href="'.UpdateSettings::getUrl().'" class="og-link">Version 1.0.0</a>');
    });

    it('shows the version in the menu as plain text when update checks are off', function () {
        Setting::put(SiteSettings::UPDATE_CHECKS, false);
        $this->actingAs(updateAdmin());

        $this->get('/admin')->assertOk()->assertSee('Version 1.0.0')->assertDontSeeHtml('class="og-link">Version 1.0.0</a>');
    });

    it('goes away once the new version is installed', function () {
        File::put($this->versionFile, '1.1.0');
        $this->actingAs(updateAdmin());

        $this->get('/admin')->assertOk()->assertDontSee('Update to 1.1.0');
    });
});
