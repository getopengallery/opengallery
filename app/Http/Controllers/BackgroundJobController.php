<?php

namespace App\Http\Controllers;

use App\Enums\BackgroundJobMode;
use App\Support\BackgroundJobs;
use App\Support\SiteSettings;
use Illuminate\Http\Response;

/**
 * The two web triggers of the background jobs (App\Support\BackgroundJobs).
 * Both are exempt from CSRF checks: they take no input and a pass runs at
 * most once a minute, however often they are called.
 */
class BackgroundJobController extends Controller
{
    /** Called by visitors' browsers (navigator.sendBeacon) while "AJAX" is chosen. */
    public function ajax(): Response
    {
        if (SiteSettings::backgroundJobs() === BackgroundJobMode::Ajax) {
            BackgroundJobs::run(BackgroundJobMode::Ajax);
        }

        return response()->noContent();
    }

    /** The secret webcron address; only answers while "Webcron" is chosen. */
    public function webcron(string $token): Response
    {
        abort_unless(
            SiteSettings::backgroundJobs() === BackgroundJobMode::Webcron && BackgroundJobs::isWebcronToken($token),
            404,
        );

        $ran = BackgroundJobs::run(BackgroundJobMode::Webcron);

        return response($ran ? 'OK' : 'OK (skipped: the last run was less than a minute ago)', 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
