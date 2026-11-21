<?php

use App\Enums\BackgroundJobMode;
use App\Support\BackgroundJobs;
use App\Support\PhotoUploads;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('gallery:prune-uploads', function () {
    $this->info('Removed '.PhotoUploads::prune().' unfinished upload file(s).');
})->purpose('Remove photo uploads that were not finished within a day');

Artisan::command('gallery:background-jobs', function () {
    $this->info(BackgroundJobs::run(BackgroundJobMode::Cron)
        ? 'Background jobs done.'
        : 'Skipped: the last run was less than a minute ago, or one is still running.');
})->purpose('Run the background jobs (activity log retention, upload clean-up, queued thumbnails)');

// The cron job's `schedule:run` starts the background jobs whatever is chosen in
// Settings; each task inside keeps its own interval (App\Support\BackgroundJobs).
Schedule::command('gallery:background-jobs')->everyMinute();
