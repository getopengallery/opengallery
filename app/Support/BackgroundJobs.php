<?php

namespace App\Support;

use App\Enums\BackgroundJobMode;
use App\Models\Setting;
use App\Support\Dashboard\SiteHealth;
use App\Support\Updates\UpdateInstaller;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Regular housekeeping ("background jobs"), started the way chosen in
 * Admin → Settings (SiteSettings::backgroundJobs()): by visitors' browsers
 * (AJAX), by an outside service opening the webcron address, or by the
 * server's cron job (`schedule:run`, which calls `gallery:background-jobs`).
 *
 * run() makes at most one pass per MIN_SECONDS. Each task in tasks() runs
 * when its own interval has passed. A pass then sends waiting e-mails
 * (MailQueue) and, with queued conversions on the database queue, works
 * through waiting jobs, together for about QUEUE_SECONDS, so no separate queue
 * worker is needed.
 */
class BackgroundJobs
{
    /** Setting: the last pass as ['at' => unix time, 'via' => BackgroundJobMode value]. */
    public const LAST_RUN = 'jobs.last_run';

    /** Setting: task key => unix time it last ran. */
    public const TASKS_RUN = 'jobs.tasks';

    /** Setting: the secret part of the webcron address. */
    public const WEBCRON_TOKEN = 'jobs.webcron_token';

    /** Shortest time between two passes, in seconds (a little under a minute, so a cron job every minute is never skipped). */
    public const MIN_SECONDS = 55;

    /** How long after a pass visitors' browsers start the next one (AJAX), in seconds. */
    public const AJAX_SECONDS = 300;

    /** The same while thumbnails are made in the background or e-mails wait, so they do not wait long. */
    public const AJAX_QUEUE_SECONDS = 60;

    /** How long one pass works through queued jobs, in seconds. */
    public const QUEUE_SECONDS = 20;

    /** Without a pass for this long, site health reports that the jobs do not run. */
    public const LATE_SECONDS = 26 * 3600;

    private const DAY = 86400;

    private const LOCK = 'gallery.background-jobs';

    /** Cache key prefix: when each trigger last called run(), even if the pass was skipped. */
    private const SEEN = 'gallery.background-jobs.seen.';

    /**
     * The tasks of a pass and how often each runs, in seconds.
     *
     * @return array<string, array{interval: int, run: callable(): mixed}>
     */
    private static function tasks(): array
    {
        return [
            // Activity log retention (ActivityEvent is mass-prunable).
            'prune-models' => ['interval' => self::DAY, 'run' => fn () => Artisan::call('model:prune')],
            'prune-uploads' => ['interval' => self::DAY, 'run' => fn () => PhotoUploads::prune()],
            // The `small` size for photos uploaded before it existed, a few seconds per pass.
            'small-thumbnails' => ['interval' => self::MIN_SECONDS, 'run' => fn () => SmallThumbnails::makeMissing()],
            // The site health checks that scan tables and files, so the dashboard only reads them;
            // then people who change settings are told about new problems.
            'site-health' => ['interval' => SiteHealth::REFRESH_SECONDS, 'run' => function (): void {
                SiteHealth::refresh();
                SiteHealthNotices::check();
            }],
            // Notifications older than Inbox::KEEP_DAYS.
            'prune-notifications' => ['interval' => self::DAY, 'run' => fn () => Inbox::prune()],
            // Does nothing unless the admin turned update checks on.
            'update-check' => ['interval' => self::DAY, 'run' => fn () => UpdateCheck::run()],
            // Update preparations nobody finished.
            'prune-updates' => ['interval' => self::DAY, 'run' => fn () => UpdateInstaller::prune()],
        ];
    }

    /**
     * Makes a pass unless the last one was less than MIN_SECONDS ago or
     * another pass is running.
     *
     * @return bool whether a pass ran
     */
    public static function run(BackgroundJobMode $via): bool
    {
        Cache::put(self::SEEN.$via->value, now()->getTimestamp(), self::LATE_SECONDS);

        if (! self::due(self::MIN_SECONDS)) {
            return false;
        }

        if (! app()->runningInConsole()) {
            ignore_user_abort(true);
            @set_time_limit(self::QUEUE_SECONDS + 120);
        }

        return (bool) Cache::lock(self::LOCK, self::QUEUE_SECONDS + 600)->get(function () use ($via): bool {
            if (! self::due(self::MIN_SECONDS)) {
                return false;
            }

            Setting::put(self::LAST_RUN, ['at' => now()->getTimestamp(), 'via' => $via->value]);

            $ran = (array) Setting::get(self::TASKS_RUN, []);
            foreach (self::tasks() as $key => $task) {
                if (now()->getTimestamp() - (int) ($ran[$key] ?? 0) < $task['interval']) {
                    continue;
                }

                try {
                    $task['run']();
                } catch (Throwable $exception) {
                    report($exception);
                }

                $ran[$key] = now()->getTimestamp();
            }
            Setting::put(self::TASKS_RUN, $ran);

            $queueStarted = microtime(true);

            try {
                MailQueue::work();
            } catch (Throwable $exception) {
                report($exception);
            }

            if (self::worksQueue()) {
                try {
                    Artisan::call('queue:work', [
                        'connection' => config('queue.default'),
                        '--stop-when-empty' => true,
                        '--max-time' => max(5, self::QUEUE_SECONDS - (int) (microtime(true) - $queueStarted)),
                        '--tries' => 3,
                    ]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return true;
        });
    }

    /** Whether a page should start a pass from the visitor's browser now. */
    public static function ajaxDue(): bool
    {
        if (SiteSettings::backgroundJobs() !== BackgroundJobMode::Ajax) {
            return false;
        }

        return self::due(self::AJAX_SECONDS)
            || (self::due(self::AJAX_QUEUE_SECONDS) && (self::worksQueue() || MailQueue::hasWork()));
    }

    /**
     * Whether the jobs seem not to run: no pass for LATE_SECONDS, or never
     * one while cron or webcron is chosen. (With AJAX the next page view
     * starts the first pass.)
     */
    public static function isLate(): bool
    {
        $last = self::lastRun();

        if ($last === null) {
            return SiteSettings::backgroundJobs() !== BackgroundJobMode::Ajax;
        }

        return $last['at']->lt(now()->subSeconds(self::LATE_SECONDS));
    }

    /** @return array{at: CarbonImmutable, via: ?BackgroundJobMode}|null the last pass, if any */
    public static function lastRun(): ?array
    {
        $last = Setting::get(self::LAST_RUN);

        if (! is_array($last) || ! is_numeric($last['at'] ?? null)) {
            return null;
        }

        return [
            'at' => CarbonImmutable::createFromTimestamp((int) $last['at'], config('app.timezone')),
            'via' => BackgroundJobMode::tryFrom((string) ($last['via'] ?? '')),
        ];
    }

    /** When $via last tried to start a pass (kept for LATE_SECONDS), so the settings page can tell whether a cron job or webcron service is set up. */
    public static function lastSeen(BackgroundJobMode $via): ?CarbonImmutable
    {
        $seen = Cache::get(self::SEEN.$via->value);

        return is_numeric($seen) ? CarbonImmutable::createFromTimestamp((int) $seen, config('app.timezone')) : null;
    }

    /** Whether queued jobs are worked through by the passes (queued conversions on the database queue). */
    public static function worksQueue(): bool
    {
        return (bool) config('gallery.queue_conversions')
            && config('queue.connections.'.config('queue.default').'.driver') === 'database';
    }

    public static function webcronUrl(): string
    {
        return route('background-jobs.webcron', ['token' => self::webcronToken()]);
    }

    /** Whether $token is the secret of the webcron address. */
    public static function isWebcronToken(string $token): bool
    {
        $stored = Setting::get(self::WEBCRON_TOKEN);

        return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }

    /** Replaces the webcron address; the old one stops working. */
    public static function renewWebcronToken(): void
    {
        Setting::put(self::WEBCRON_TOKEN, Str::random(40));
    }

    private static function webcronToken(): string
    {
        if (! is_string(Setting::get(self::WEBCRON_TOKEN))) {
            self::renewWebcronToken();
        }

        return (string) Setting::get(self::WEBCRON_TOKEN);
    }

    /** Whether the last pass was at least $seconds ago (or there was none). */
    private static function due(int $seconds): bool
    {
        $last = self::lastRun();

        return $last === null || $last['at']->getTimestamp() <= now()->getTimestamp() - $seconds;
    }
}
