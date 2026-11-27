<?php

namespace App\Support\Statistics;

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\StatisticsRange;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Visits from the activity log: album views, logins and downloads over
 * time, the activity calendar and album and photo rankings. Counts are
 * site-wide and cached for CACHE_SECONDS; rankings are cut down to what the
 * viewer may list after reading them from the cache.
 *
 * Album views and downloads are only recorded at the activity level
 * "Everything", logins from "Changes and logins"; entries older than the
 * log's retention are gone. A visitor counts once per album (or photo) and day.
 */
class VisitStats
{
    public const CACHE_SECONDS = 600;

    public const MEMBERS = 'members';

    public const GUESTS = 'guests';

    /**
     * Views (members and guests), logins and downloads per bucket of $range.
     *
     * @return list<array{start: CarbonImmutable, memberViews: int, guestViews: int, logins: int, downloads: int}>
     */
    public static function series(StatisticsRange $range): array
    {
        return self::bucketed(self::daily($range->start()), $range->buckets(), fn (CarbonImmutable $day) => $range->bucketOf($day));
    }

    /**
     * The same per week for the last $weeks weeks (Monday first, the
     * current, unfinished week last).
     *
     * @return list<array{start: CarbonImmutable, memberViews: int, guestViews: int, logins: int, downloads: int}>
     */
    public static function weeks(int $weeks): array
    {
        $start = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->subWeeks($weeks - 1);
        $buckets = array_map(fn (int $week) => $start->addWeeks($week), range(0, $weeks - 1));

        return self::bucketed(self::daily($start), $buckets, fn (CarbonImmutable $day) => $day->startOfWeek(CarbonImmutable::MONDAY));
    }

    /**
     * Totals of series() for the whole period.
     *
     * @return array{views: int, guestViews: int, logins: int, downloads: int}
     */
    public static function totals(StatisticsRange $range): array
    {
        $totals = ['views' => 0, 'guestViews' => 0, 'logins' => 0, 'downloads' => 0];

        foreach (self::series($range) as $bucket) {
            $totals['views'] += $bucket['memberViews'] + $bucket['guestViews'];
            $totals['guestViews'] += $bucket['guestViews'];
            $totals['logins'] += $bucket['logins'];
            $totals['downloads'] += $bucket['downloads'];
        }

        return $totals;
    }

    /**
     * Daily activity for a contribution-style calendar: full weeks (Monday
     * first) ending with the current week, one cell per day.
     *
     * Members: distinct logged-in people with any log entry that day.
     * Guests: distinct anonymous visitors that day.
     *
     * @return array{
     *     weeks: list<list<array{date: CarbonImmutable, count: int, level: int, future: bool}>>,
     *     activeDays: int,
     *     people: int,
     *     max: int,
     * }
     */
    public static function calendar(string $mode = self::MEMBERS, int $weeks = 53): array
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfWeek(CarbonImmutable::MONDAY)->subWeeks($weeks - 1);
        $column = $mode === self::GUESTS ? 'visitor' : 'user_id';

        $query = fn () => ActivityEvent::query()
            ->where('created_at', '>=', $start)
            ->whereNotNull($column)
            ->when($mode === self::GUESTS, fn (Builder $query) => $query->whereNull('user_id'));

        ['counts' => $counts, 'people' => $people] = Cache::remember(
            "statistics.calendar.{$mode}.{$weeks}.{$today->toDateString()}",
            self::CACHE_SECONDS,
            fn () => [
                'counts' => $query()
                    ->selectRaw("DATE(created_at) as day, COUNT(DISTINCT {$column}) as people")
                    ->groupBy('day')
                    ->pluck('people', 'day')
                    ->map(fn ($n) => (int) $n)
                    ->all(),
                'people' => (int) $query()->distinct()->count($column),
            ],
        );

        $max = $counts === [] ? 0 : max($counts);
        $level = fn (int $count) => $count === 0 || $max === 0 ? 0 : max(1, (int) ceil($count / $max * 4));

        $grid = [];
        for ($week = 0; $week < $weeks; $week++) {
            $days = [];
            for ($d = 0; $d < 7; $d++) {
                $date = $start->addDays($week * 7 + $d);
                $count = $counts[$date->toDateString()] ?? 0;
                $days[] = ['date' => $date, 'count' => $count, 'level' => $level($count), 'future' => $date->isAfter($today)];
            }
            $grid[] = $days;
        }

        return [
            'weeks' => $grid,
            'activeDays' => count(array_filter($counts)),
            'people' => $people,
            'max' => $max,
        ];
    }

    /**
     * The most viewed albums of $range among those $viewer may list, with
     * their zip downloads.
     *
     * @return Collection<int, object{album: Album, views: int, downloads: int}>
     */
    public static function topAlbums(StatisticsRange $range, User $viewer, int $limit = 10): Collection
    {
        $start = $range->start();
        $counts = Cache::remember("statistics.album-visits.{$range->value}.{$start->toDateString()}", self::CACHE_SECONDS, fn () => ActivityEvent::query()
            ->whereIn('type', [ActivityType::AlbumView, ActivityType::ZipDownload])
            ->where('created_at', '>=', $start)
            ->whereNotNull('album_id')
            ->selectRaw('album_id, type, COUNT(*) as n')
            ->groupBy('album_id', 'type')
            ->toBase()
            ->get()
            ->groupBy('album_id')
            ->map(fn (Collection $rows) => [
                'views' => (int) $rows->firstWhere('type', ActivityType::AlbumView->value)?->n,
                'downloads' => (int) $rows->firstWhere('type', ActivityType::ZipDownload->value)?->n,
            ])
            ->all());

        $counts = collect($counts)->sortByDesc(fn (array $row) => [$row['views'], $row['downloads']]);

        if (! $viewer->can(Permission::ViewAnyAlbum->value)) {
            $listable = Album::query()->listableFor($viewer)->whereKey($counts->keys()->all())->pluck('id')->flip();
            $counts = $counts->filter(fn (array $row, int $id) => $listable->has($id));
        }

        $counts = $counts->take($limit);
        $albums = Album::whereKey($counts->keys()->all())->get()->keyBy('id');

        return $counts
            ->filter(fn (array $row, int $id) => $albums->has($id))
            ->map(fn (array $row, int $id) => (object) ['album' => $albums[$id], ...$row])
            ->values();
    }

    /**
     * Albums nobody opened during $range (not counting new ones created in it).
     *
     * @return array{count: int, albums: \Illuminate\Database\Eloquent\Collection<int, Album>}
     */
    public static function unopenedAlbums(StatisticsRange $range, int $limit = 10): array
    {
        $start = $range->start();

        $query = fn () => Album::query()
            ->whereNull('system_album')
            ->where('created_at', '<', $start)
            ->whereNotIn('id', ActivityEvent::query()
                ->where('type', ActivityType::AlbumView)
                ->where('created_at', '>=', $start)
                ->whereNotNull('album_id')
                ->select('album_id'));

        $ids = Cache::remember("statistics.unopened.{$range->value}.{$start->toDateString()}", self::CACHE_SECONDS, fn () => [
            'count' => $query()->count(),
            'ids' => $query()->withCount('photos')->orderByDesc('photos_count')->limit($limit)->pluck('id')->all(),
        ]);

        return [
            'count' => $ids['count'],
            'albums' => Album::whereKey($ids['ids'])->withCount('photos')->orderByDesc('photos_count')->get(),
        ];
    }

    /**
     * The photos whose originals were downloaded most often during $range.
     *
     * @return Collection<int, object{photo: Photo, downloads: int}>
     */
    public static function topDownloads(StatisticsRange $range, int $limit = 10): Collection
    {
        $start = $range->start();
        $rows = Cache::remember("statistics.photo-downloads.{$range->value}.{$start->toDateString()}.{$limit}", self::CACHE_SECONDS, fn () => ActivityEvent::query()
            ->where('type', ActivityType::PhotoDownload)
            ->where('created_at', '>=', $start)
            ->where('subject_type', (new Photo)->getMorphClass())
            ->selectRaw('subject_id, COUNT(*) as n')
            ->groupBy('subject_id')
            ->orderByDesc('n')
            ->limit($limit)
            ->toBase()
            ->pluck('n', 'subject_id')
            ->map(fn ($n) => (int) $n)
            ->all());

        $photos = Photo::whereKey(array_keys($rows))->with('media')->get()->keyBy('id');

        return collect($rows)
            ->filter(fn (int $n, int $id) => $photos->has($id))
            ->map(fn (int $n, int $id) => (object) ['photo' => $photos[$id], 'downloads' => $n])
            ->values();
    }

    /**
     * Entries per day, type and guest/member since $start, cached.
     *
     * @return list<array{day: string, type: string, guest: bool, n: int}>
     */
    private static function daily(CarbonImmutable $start): array
    {
        return Cache::remember("statistics.daily.{$start->toDateString()}", self::CACHE_SECONDS, fn () => ActivityEvent::query()
            ->where('created_at', '>=', $start)
            ->whereIn('type', ActivityType::visits())
            ->selectRaw('DATE(created_at) as day, type, CASE WHEN user_id IS NULL THEN 1 ELSE 0 END as guest, COUNT(*) as n')
            ->groupBy('day', 'type', 'guest')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['day' => (string) $row->day, 'type' => (string) $row->type, 'guest' => (bool) $row->guest, 'n' => (int) $row->n])
            ->all());
    }

    /**
     * @param  list<array{day: string, type: string, guest: bool, n: int}>  $rows
     * @param  list<CarbonImmutable>  $buckets
     * @param  callable(CarbonImmutable): CarbonImmutable  $bucketOf
     * @return list<array{start: CarbonImmutable, memberViews: int, guestViews: int, logins: int, downloads: int}>
     */
    private static function bucketed(array $rows, array $buckets, callable $bucketOf): array
    {
        $series = [];
        foreach ($buckets as $bucket) {
            $series[$bucket->toDateString()] = ['start' => $bucket, 'memberViews' => 0, 'guestViews' => 0, 'logins' => 0, 'downloads' => 0];
        }

        foreach ($rows as $row) {
            $key = $bucketOf(CarbonImmutable::parse($row['day']))->toDateString();
            if (! isset($series[$key])) {
                continue;
            }

            $field = match (true) {
                $row['type'] === ActivityType::Login->value => 'logins',
                in_array($row['type'], [ActivityType::PhotoDownload->value, ActivityType::ZipDownload->value], true) => 'downloads',
                $row['guest'] => 'guestViews',
                default => 'memberViews',
            };
            $series[$key][$field] += $row['n'];
        }

        return array_values($series);
    }
}
