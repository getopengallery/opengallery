<?php

use App\Enums\BackgroundJobMode;
use App\Enums\UpdateChannel;
use App\Models\Setting;
use App\Support\AppVersion;
use App\Support\BackgroundJobs;
use App\Support\SiteSettings;
use App\Support\UpdateCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

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
