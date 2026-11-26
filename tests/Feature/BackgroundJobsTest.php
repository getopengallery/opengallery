<?php

use App\Enums\BackgroundJobMode;
use App\Filament\Clusters\Settings\Pages\BackgroundJobSettings;
use App\Models\ActivityEvent;
use App\Models\Setting;
use App\Support\BackgroundJobs;
use App\Support\Dashboard\SiteHealth as SiteHealthChecks;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    config(['gallery.activity_retention_days' => 30]);
});

it('runs each task once per interval, and at most one pass a minute', function () {
    $this->freezeTime();
    $first = staleActivityEvent();

    $this->artisan('gallery:background-jobs')->expectsOutput('Background jobs done.');
    expect(ActivityEvent::find($first->id))->toBeNull();

    $second = staleActivityEvent();
    $this->artisan('gallery:background-jobs')->expectsOutputToContain('Skipped');

    $this->travel(2)->hours();
    $this->artisan('gallery:background-jobs')->expectsOutput('Background jobs done.');
    expect(ActivityEvent::find($second->id))->not->toBeNull('the daily clean-up already ran today');

    $this->travel(1)->day();
    $this->artisan('gallery:background-jobs');
    expect(ActivityEvent::find($second->id))->toBeNull();
});

it('lets the cron job run whatever is chosen in the settings', function () {
    chooseBackgroundJobs(BackgroundJobMode::Ajax);
    $event = staleActivityEvent();

    $this->artisan('gallery:background-jobs')->assertSuccessful();

    expect(ActivityEvent::find($event->id))->toBeNull()
        ->and(BackgroundJobs::lastRun()['via'])->toBe(BackgroundJobMode::Cron);
});

it('works through jobs waiting on the database queue when thumbnails are made in the background', function () {
    config(['gallery.queue_conversions' => true, 'queue.default' => 'database']);
    dispatch(fn () => Setting::put('test.job', 'done'));
    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('gallery:background-jobs')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(Setting::get('test.job'))->toBe('done');
});

describe('AJAX', function () {
    it('has pages start a pass from the browser only while one is due', function () {
        $this->get(route('login'))->assertSee('navigator.sendBeacon', false);

        $this->post(route('background-jobs.ajax'))->assertNoContent();

        expect(BackgroundJobs::lastRun()['via'])->toBe(BackgroundJobMode::Ajax);
        $this->get(route('login'))->assertDontSee('navigator.sendBeacon', false);

        $this->travel(BackgroundJobs::AJAX_SECONDS + 1)->seconds();
        $this->get(route('login'))->assertSee('navigator.sendBeacon', false);
    });

    it('does nothing while another trigger is chosen', function (BackgroundJobMode $mode) {
        chooseBackgroundJobs($mode);

        $this->get(route('login'))->assertDontSee('navigator.sendBeacon', false);
        $this->post(route('background-jobs.ajax'))->assertNoContent();

        expect(BackgroundJobs::lastRun())->toBeNull();
    })->with([BackgroundJobMode::Webcron, BackgroundJobMode::Cron]);
});

describe('webcron', function () {
    it('runs a pass when the secret address is opened', function () {
        chooseBackgroundJobs(BackgroundJobMode::Webcron);
        $event = staleActivityEvent();

        $this->get(BackgroundJobs::webcronUrl())->assertOk()->assertSeeText('OK');

        expect(ActivityEvent::find($event->id))->toBeNull()
            ->and(BackgroundJobs::lastRun()['via'])->toBe(BackgroundJobMode::Webcron);
    });

    it('refuses a wrong address', function () {
        chooseBackgroundJobs(BackgroundJobMode::Webcron);
        BackgroundJobs::webcronUrl();

        $this->get(route('background-jobs.webcron', ['token' => 'guessed']))->assertNotFound();

        expect(BackgroundJobs::lastRun())->toBeNull();
    });

    it('does not answer while another trigger is chosen', function () {
        chooseBackgroundJobs(BackgroundJobMode::Cron);

        $this->get(BackgroundJobs::webcronUrl())->assertNotFound();
    });
});

describe('settings page', function () {
    it('saves what starts the background jobs', function () {
        $this->actingAs(userWithRole());

        Livewire::test(BackgroundJobSettings::class)
            ->set('data.background_jobs', BackgroundJobMode::Webcron->value)
            ->call('save')
            ->assertHasNoErrors();

        expect(SiteSettings::backgroundJobs())->toBe(BackgroundJobMode::Webcron);
    });

    it('shows the cron line of this installation and warns until the cron job runs', function () {
        $this->actingAs(userWithRole());

        Livewire::test(BackgroundJobSettings::class)
            ->set('data.background_jobs', BackgroundJobMode::Cron->value)
            ->assertSet('data.cron_command', '* * * * * php '.base_path('artisan').' schedule:run')
            ->assertSee('No cron job has started the background jobs in the last hour.');

        $this->artisan('gallery:background-jobs');

        Livewire::test(BackgroundJobSettings::class)
            ->set('data.background_jobs', BackgroundJobMode::Cron->value)
            ->assertDontSee('No cron job has started the background jobs in the last hour.');
    });

    it('replaces the webcron address, so the old one stops working', function () {
        chooseBackgroundJobs(BackgroundJobMode::Webcron);
        $this->actingAs(userWithRole());
        $old = BackgroundJobs::webcronUrl();

        Livewire::test(BackgroundJobSettings::class)
            ->callAction(TestAction::make('renewWebcronAddress')->schemaComponent('webcron_url', schema: 'form'))
            ->assertSet('data.webcron_url', fn (string $url) => $url !== $old);

        $this->get($old)->assertNotFound();
        $this->get(BackgroundJobs::webcronUrl())->assertOk();
    });
});

describe('site health', function () {
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
