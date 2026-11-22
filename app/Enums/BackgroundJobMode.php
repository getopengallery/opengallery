<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What starts the background jobs (Admin → System → Background jobs, see
 * App\Support\BackgroundJobs). The server's cron job always works, whatever
 * is chosen; the mode decides which of the two web triggers is accepted.
 */
enum BackgroundJobMode: string implements HasDescription, HasLabel
{
    /** Visitors' browsers start a pass after a page has loaded. */
    case Ajax = 'ajax';
    /** An external service opens the secret webcron address. */
    case Webcron = 'webcron';
    /** The server's cron job runs `php artisan schedule:run` every minute. */
    case Cron = 'cron';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ajax => __('AJAX (with page views)'),
            self::Webcron => __('Webcron'),
            self::Cron => __('Cron'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Ajax => __('Runs when someone opens a page, at most every few minutes. Needs no setup, but nothing happens while nobody visits the site.'),
            self::Webcron => __('An outside service (for example your host\'s or cron-job.org) opens a secret address regularly. For hosts without cron jobs.'),
            self::Cron => __('The server runs a cron job every minute. Most reliable; recommended if your host offers cron jobs.'),
        };
    }
}
