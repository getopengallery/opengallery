<?php

use App\Enums\BackgroundJobMode;
use App\Models\ActivityEvent;
use App\Models\Setting;
use App\Support\BackgroundJobs;
use Illuminate\Support\Facades\DB;

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
