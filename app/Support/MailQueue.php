<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * E-mails that may go to many people wait here and are sent in portions by
 * the background jobs (BackgroundJobs::run()), so saving an album never waits
 * for a mail server and a host's sending limit is respected. Uses the database
 * queue whatever QUEUE_CONNECTION says, and needs no queue worker.
 *
 * MAIL holds the e-mails, limited per pass and per hour. NOTICES holds work
 * that must not happen during the request but is not an e-mail itself.
 */
final class MailQueue
{
    public const CONNECTION = 'database';

    public const MAIL = 'mail';

    public const NOTICES = 'notices';

    /** @var list<string> */
    public const QUEUES = [self::MAIL, self::NOTICES];

    /** How often an e-mail is tried before it counts as failed. */
    public const TRIES = 5;

    /** Seconds to wait before each further try: 5 minutes, 15 minutes, 1 hour, 6 hours. */
    public const RETRY_SECONDS = [300, 900, 3600, 21600];

    /** Most e-mails one background pass sends. */
    public const PER_PASS = 50;

    /** How long one pass sends e-mails, in seconds. */
    public const MAIL_SECONDS = 10;

    /** How long one pass works through NOTICES, in seconds. */
    public const NOTICE_SECONDS = 3;

    /**
     * The worker's own memory check, set out of the way: it is meant for
     * long-running workers and would stop this short run after one e-mail when
     * the pass has already used memory. PHP's memory_limit still applies.
     */
    private const WORKER_MEMORY_MB = 4096;

    private const SENT = 'gallery.mail-sent';

    private const HOUR = 3600;

    public static function push(object $notifiable, Notification $notification): void
    {
        Bus::dispatch((new SendQueuedNotifications($notifiable, $notification, ['mail']))
            ->onConnection(self::CONNECTION)
            ->onQueue(self::MAIL));
    }

    /** Runs $task in the next background pass instead of during this request. */
    public static function later(Closure $task): void
    {
        dispatch($task)->onConnection(self::CONNECTION)->onQueue(self::NOTICES);
    }

    /** Counts a message that left the server towards the hourly limit (MessageSent). */
    public static function countSent(): void
    {
        if (! Mailing::usesTestMailbox()) {
            RateLimiter::hit(self::SENT, self::HOUR);
        }
    }

    /** E-mails still waiting, including those waiting for another try. */
    public static function waiting(): int
    {
        return self::jobs()->where('queue', self::MAIL)->count();
    }

    /** Whether a background pass could do something right now. */
    public static function hasWork(): bool
    {
        return self::isDue(self::NOTICES) || (self::allowance() > 0 && self::isDue(self::MAIL));
    }

    /** Works through what is due, within the limits. Called by every background pass. */
    public static function work(): void
    {
        if (self::isDue(self::NOTICES)) {
            self::runWorker(self::NOTICES, self::NOTICE_SECONDS);
        }

        $allowance = self::allowance();

        if ($allowance > 0 && self::isDue(self::MAIL)) {
            self::runWorker(self::MAIL, self::MAIL_SECONDS, $allowance);
        }
    }

    /** How many e-mails this pass may send; none while e-mail does not work, so they wait. */
    private static function allowance(): int
    {
        if (! Mailing::delivers()) {
            return 0;
        }

        return max(0, min(self::PER_PASS, RateLimiter::remaining(self::SENT, SiteSettings::mailHourlyLimit())));
    }

    private static function isDue(string $queue): bool
    {
        return self::jobs()
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->exists();
    }

    private static function runWorker(string $queue, int $seconds, ?int $maxJobs = null): void
    {
        Artisan::call('queue:work', array_filter([
            'connection' => self::CONNECTION,
            '--queue' => $queue,
            '--stop-when-empty' => true,
            '--max-time' => $seconds,
            '--max-jobs' => $maxJobs,
            '--memory' => self::WORKER_MEMORY_MB,
            '--tries' => self::TRIES,
        ]));
    }

    private static function jobs(): Builder
    {
        return DB::table(config('queue.connections.'.self::CONNECTION.'.table', 'jobs'));
    }
}
