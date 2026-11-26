<?php

use App\Enums\ActivityLevel;
use App\Enums\BackgroundJobMode;
use App\Enums\Role;
use App\Filament\Widgets\SiteHealth;
use App\Models\TestMail;
use App\Models\User;
use App\Notifications\QueuedMailChannel;
use App\Notifications\SiteProblemFound;
use App\Support\AppVersion;
use App\Support\BackgroundJobs;
use App\Support\Dashboard\SiteHealth as SiteHealthChecks;
use App\Support\Inbox;
use App\Support\SiteHealthNotices;
use App\Support\UpdateCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

describe('notifying about problems', function () {
    beforeEach(function () {
        config(['gallery.activity_log_level' => ActivityLevel::All->value, 'mail.default' => 'smtp']);
    });

    it('only remembers the problems of the very first check', function () {
        $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);
        config(['mail.default' => 'mailbox']);

        expect(SiteHealthNotices::check())->toBe(0)
            ->and($ada->notifications()->count())->toBe(0);
    });

    it('tells people who change settings once about a new problem and marks it read when it is fixed', function () {
        $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);
        $moderator = tap(User::factory()->create())->assignRole(Role::Moderator->value);
        SiteHealthNotices::check();

        config(['mail.default' => 'mailbox']);
        SiteHealthNotices::check();
        SiteHealthNotices::check();
        $notice = $ada->notifications()->sole();

        expect($notice->type)->toBe(SiteProblemFound::class)
            ->and(Inbox::message($notice)->title)->toBe('E-mails are not sent')
            ->and($moderator->notifications()->count())->toBe(0)
            ->and(TestMail::query()->count())->toBe(0);

        config(['mail.default' => 'smtp']);
        SiteHealthNotices::check();

        expect($notice->fresh()->read_at)->not->toBeNull();
    });

    it('also e-mails problems that stop the site from working', function () {
        Notification::fake();
        $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);
        SiteHealthNotices::check();

        config(['app.debug' => true]);
        $this->app['env'] = 'production';
        SiteHealthNotices::check();

        Notification::assertSentTo($ada, SiteProblemFound::class, fn (SiteProblemFound $notice, array $channels) => $notice->problem['key'] === 'debug-mode' && $channels === ['database', QueuedMailChannel::class]);
    });
});

describe('the dashboard checks', function () {
    it('reports when the background jobs have not run for over a day', function () {
        $this->artisan('gallery:background-jobs');
        expect(BackgroundJobs::isLate())->toBeFalse();

        $this->travel(2)->days();

        expect(BackgroundJobs::isLate())->toBeTrue();
    });

    it('makes the slower checks, so the dashboard only reads them', function () {
        $this->freezeTime();
        $this->artisan('gallery:background-jobs');

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'Failed', 'failed_at' => now(),
        ]);
        $failedJobs = fn () => collect(SiteHealthChecks::report()['problems'])->firstWhere('key', 'failed-jobs');

        expect($failedJobs())->toBeNull('the dashboard does not count again');

        $this->travel(SiteHealthChecks::REFRESH_SECONDS)->seconds();
        $this->artisan('gallery:background-jobs');

        expect($failedJobs()['count'])->toBe(1);
    });

    it('reports cron or webcron that never ran, but waits for the first page view with AJAX', function () {
        expect(BackgroundJobs::isLate())->toBeFalse();

        chooseBackgroundJobs(BackgroundJobMode::Cron);

        expect(BackgroundJobs::isLate())->toBeTrue();
    });
});

describe('the update notice', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        config(['gallery.update_repository' => 'getopengallery/opengallery']);
        $this->versionFile = tempnam(sys_get_temp_dir(), 'og-version-');
        File::put($this->versionFile, '1.0.0');
        AppVersion::useFile($this->versionFile);
        turnOnUpdateChecks();
        Http::fake([UPDATE_API => Http::response([githubRelease('v1.1.0')])]);
        UpdateCheck::run();
    });

    afterEach(function () {
        AppVersion::useFile(null);
        File::delete($this->versionFile);
    });

    it('notes an update in site health', function () {
        $this->actingAs(userWithRole());

        Livewire::test(SiteHealth::class)
            ->assertSee('Update available')
            ->assertSee('OpenGallery 1.1.0 is available. This gallery runs 1.0.0.')
            ->assertSee('System → Updates');
    });
});
