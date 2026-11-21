<?php

namespace App\Support;

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Read-only queries behind the activity and library widgets of the admin
 * dashboard. Site-wide figures are cached briefly (CACHE_SECONDS) in the
 * application cache (file or database on shared hosting); anything naming
 * albums is limited to what the viewer may list (Album::listableFor()).
 * Other widgets read through App\Support\Dashboard\*.
 */
class DashboardStats
{
    public const MEMBERS = 'members';

    public const GUESTS = 'guests';

    public const CACHE_SECONDS = 600;

    /**
     * @return array{albums: int, photos: int, users: int, groups: int, bytes: int}
     */
    public static function totals(): array
    {
        return Cache::remember('dashboard.totals', self::CACHE_SECONDS, fn () => [
            'albums' => Album::query()->whereNull('system_album')->count(),
            'photos' => Photo::count(),
            'users' => User::count(),
            'groups' => Group::count(),
            'bytes' => (int) Media::sum('size'),
        ]);
    }

    /**
     * Daily activity for a contribution-style calendar: full weeks (Monday
     * first) ending with the current week, one cell per day.
     *
     * Members: distinct logged-in people active that day (logged in or
     * opened an album). Guests: distinct anonymous visitors that day.
     * The counts are cached for CACHE_SECONDS; the grid is built per request.
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
            "dashboard.calendar.{$mode}.{$weeks}.{$today->toDateString()}",
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
     * Album views and logins per week for the last $weeks weeks (the current
     * week last), album views split into members and guests.
     *
     * @return list<array{start: CarbonImmutable, memberViews: int, guestViews: int, logins: int}>
     */
    public static function trends(int $weeks = 12): array
    {
        $start = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->subWeeks($weeks - 1);

        $rows = Cache::remember("dashboard.trends.{$weeks}.{$start->toDateString()}", self::CACHE_SECONDS, fn () => ActivityEvent::query()
            ->where('created_at', '>=', $start)
            ->whereIn('type', [ActivityType::AlbumView, ActivityType::Login])
            ->selectRaw('DATE(created_at) as day, type, CASE WHEN user_id IS NULL THEN 1 ELSE 0 END as guest, COUNT(*) as n')
            ->groupBy('day', 'type', 'guest')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['day' => (string) $row->day, 'type' => (string) $row->type, 'guest' => (bool) $row->guest, 'n' => (int) $row->n])
            ->all());

        $series = [];
        for ($week = 0; $week < $weeks; $week++) {
            $series[] = ['start' => $start->addWeeks($week), 'memberViews' => 0, 'guestViews' => 0, 'logins' => 0];
        }

        foreach ($rows as $row) {
            $week = intdiv((int) $start->diffInDays(CarbonImmutable::parse($row['day'])), 7);
            if (! isset($series[$week])) {
                continue;
            }
            $field = match (true) {
                $row['type'] === ActivityType::Login->value => 'logins',
                $row['guest'] => 'guestViews',
                default => 'memberViews',
            };
            $series[$week][$field] += $row['n'];
        }

        return $series;
    }

    /**
     * The latest entries $viewer may know about: without "view any album",
     * entries about albums or photos they cannot list are left out (the
     * activity log itself stays complete).
     *
     * @return Collection<int, ActivityEvent>
     */
    public static function recent(User $viewer, int $limit = 12): Collection
    {
        return ActivityEvent::query()
            ->unless($viewer->can(Permission::ViewAnyAlbum->value), function (Builder $query) use ($viewer) {
                $albums = Album::query()->withTrashed()->listableFor($viewer)->select('albums.id');
                $photos = Photo::query()->withTrashed()->visibleTo($viewer)->select('photos.id');
                $albumType = (new Album)->getMorphClass();
                $photoType = (new Photo)->getMorphClass();

                $query->where(fn (Builder $q) => $q->whereNull('album_id')->orWhereIn('album_id', $albums))
                    ->where(fn (Builder $q) => $q
                        ->whereNull('subject_type')
                        ->orWhereNotIn('subject_type', [$albumType, $photoType])
                        ->orWhere(fn (Builder $album) => $album->where('subject_type', $albumType)->whereIn('subject_id', $albums))
                        ->orWhere(fn (Builder $photo) => $photo->where('subject_type', $photoType)->whereIn('subject_id', $photos)));
            })
            ->with(['user', 'album', 'subject'])
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The most viewed albums of the last $days days among those $viewer may list.
     *
     * @return \Illuminate\Support\Collection<int, object{album: Album, views: int}>
     */
    public static function popularAlbums(User $viewer, int $days = 30, int $limit = 6): \Illuminate\Support\Collection
    {
        $rows = ActivityEvent::query()
            ->where('type', ActivityType::AlbumView)
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('album_id')
            ->unless($viewer->can(Permission::ViewAnyAlbum->value), fn (Builder $query) => $query
                ->whereIn('album_id', Album::query()->listableFor($viewer)->select('albums.id')))
            ->select('album_id', DB::raw('COUNT(*) as views'))
            ->groupBy('album_id')
            ->orderByDesc('views')
            ->limit($limit)
            ->get();

        $albums = Album::whereKey($rows->pluck('album_id'))->get()->keyBy('id');

        return $rows
            ->filter(fn ($row) => $albums->has($row->album_id))
            ->map(fn ($row) => (object) ['album' => $albums[$row->album_id], 'views' => (int) $row->views])
            ->values();
    }
}
