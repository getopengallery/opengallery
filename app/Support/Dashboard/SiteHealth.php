<?php

namespace App\Support\Dashboard;

use App\Enums\ActivityLevel;
use App\Models\Photo;
use App\Support\BackgroundJobs;
use App\Support\Mailing;
use App\Support\MailQueue;
use App\Support\ProductionCaches;
use App\Support\SiteSettings;
use App\Support\UpdateCheck;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Checks behind the "Site health" widget (people with "manage settings").
 * report() returns the problems found, each with a stable key the widget turns into
 * text and a link to what fixes it. Settings checks are read live; the ones
 * that scan tables or files are made by the background jobs every
 * REFRESH_SECONDS (refresh()), so opening the dashboard only reads them.
 */
class SiteHealth
{
    /** How often the background jobs repeat the slower checks, in seconds. */
    public const REFRESH_SECONDS = 300;

    /** Cache key of the slower checks' results. */
    private const SCANNED = 'dashboard.site-health';

    /** Queued jobs older than this (in minutes) mean no queue worker is running. */
    public const STUCK_JOB_MINUTES = 15;

    /** Less free space than this (in bytes) on the storage disk is a warning. */
    public const LOW_DISK_BYTES = 1024 ** 3;

    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** @return array{checked: int, problems: list<array{key: string, severity: string, count: ?int}>} problems most severe first */
    public static function report(): array
    {
        $scanned = Cache::get(self::SCANNED) ?? self::refresh();

        $free = function_exists('disk_free_space') ? @disk_free_space(storage_path()) : false;

        $problems = [
            ['pending-migrations', self::DANGER, $scanned['migrations']],
            ['storage-not-writable', self::DANGER, self::storageWritable() ? 0 : null],
            ['debug-mode', self::DANGER, config('app.debug') && app()->isProduction() ? null : 0],
            ['queue-stuck', self::DANGER, $scanned['stuckJobs']],
            ['failed-jobs', self::WARNING, $scanned['failedJobs']],
            ['missing-thumbnails', self::WARNING, $scanned['missingThumbnails']],
            ['low-disk-space', self::WARNING, $free !== false && $free < self::LOW_DISK_BYTES ? (int) $free : 0],
            ['opcache-off', self::WARNING, self::opcacheOff() ? null : 0],
            ['caches-not-saved', self::WARNING, ProductionCaches::failing() ? null : 0],
            ['mail-not-sent', self::WARNING, Mailing::usesTestMailbox() || ! Mailing::delivers() ? null : 0],
            ['background-jobs-idle', self::WARNING, BackgroundJobs::isLate() ? null : 0],
            ['activity-log-off', self::INFO, SiteSettings::activityLevel() === ActivityLevel::Off ? null : 0],
            ['update-available', self::INFO, UpdateCheck::available() !== null ? null : 0],
        ];

        // A count of 0 means "fine"; null means "a problem without a number".
        return [
            'checked' => count($problems),
            'problems' => array_values(array_map(
                fn (array $problem) => ['key' => $problem[0], 'severity' => $problem[1], 'count' => $problem[2]],
                array_filter($problems, fn (array $problem) => $problem[2] !== 0),
            )),
        ];
    }

    /**
     * Runs the checks that scan tables and files and keeps their results
     * (task of App\Support\BackgroundJobs). They are kept for as long as
     * the background jobs may be late before site health says so; only
     * without a result (first visit, cleared cache) does report() run them.
     *
     * @return array{migrations: int, stuckJobs: int, failedJobs: int, missingThumbnails: int}
     */
    public static function refresh(): array
    {
        $scanned = [
            'migrations' => self::pendingMigrations(),
            'stuckJobs' => self::stuckJobs(),
            'failedJobs' => self::count(fn () => DB::table('failed_jobs')->count()),
            'missingThumbnails' => self::missingThumbnails(),
        ];

        Cache::put(self::SCANNED, $scanned, BackgroundJobs::LATE_SECONDS);

        return $scanned;
    }

    private static function pendingMigrations(): int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return 1;
            }

            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);

            return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return 0;
        }
    }

    /** Queued jobs waiting longer than STUCK_JOB_MINUTES (only with queued conversions and the database queue). E-mails may wait for the hourly limit, so they do not count. */
    private static function stuckJobs(): int
    {
        if (! config('gallery.queue_conversions') || config('queue.default') !== 'database') {
            return 0;
        }

        return self::count(fn () => DB::table(config('queue.connections.database.table', 'jobs'))
            ->whereNotIn('queue', MailQueue::QUEUES)
            ->where('available_at', '<', now()->subMinutes(self::STUCK_JOB_MINUTES)->getTimestamp())
            ->whereNull('reserved_at')
            ->count());
    }

    /** Photos uploaded more than STUCK_JOB_MINUTES ago that still have no thumbnail. */
    private static function missingThumbnails(): int
    {
        return self::count(fn () => Media::query()
            ->where('model_type', (new Photo)->getMorphClass())
            ->where('collection_name', Photo::COLLECTION)
            ->where('created_at', '<', now()->subMinutes(self::STUCK_JOB_MINUTES))
            ->where(fn ($query) => $query->whereNull('generated_conversions')->orWhere('generated_conversions', 'not like', '%"thumb":true%'))
            ->count());
    }

    /**
     * Whether PHP's OPcache is off for the web server. Without it every page
     * view compiles thousands of PHP files again (measured: 300–550 ms instead
     * of 15–100 ms). Only the web server's PHP matters, so the command line
     * (where OPcache is usually off) never reports it. $running: what the
     * web server reported, for tests; null asks PHP.
     */
    public static function opcacheOff(?bool $running = null): bool
    {
        if ($running === null && app()->runningInConsole()) {
            return false;
        }

        return ! ($running ?? self::opcacheRunning());
    }

    private static function opcacheRunning(): bool
    {
        if (! function_exists('opcache_get_status')) {
            return false;
        }

        // Hosts may forbid reading the status (opcache.restrict_api); the setting is the next best answer.
        $status = @opcache_get_status(false);

        return is_array($status)
            ? (bool) ($status['opcache_enabled'] ?? false)
            : filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL);
    }

    private static function storageWritable(): bool
    {
        $gallery = Storage::disk(config('gallery.media_disk'));
        $root = method_exists($gallery, 'path') ? $gallery->path('') : null;

        return is_writable(storage_path('app'))
            && ($root === null || is_writable(is_dir($root) ? $root : dirname($root)));
    }

    /** @param callable(): int $count */
    private static function count(callable $count): int
    {
        try {
            return $count();
        } catch (Throwable) {
            return 0;
        }
    }
}
