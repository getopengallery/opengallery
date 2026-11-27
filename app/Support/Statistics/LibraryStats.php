<?php

namespace App\Support\Statistics;

use App\Enums\StatisticsRange;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\User;
use App\Support\MediaSizes;
use App\Support\PhotoCompression;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Library figures for the dashboard and the statistics page: how much there
 * is, how much disk space it takes, and how it grew. Site-wide and cached
 * for CACHE_SECONDS.
 *
 * Sizes: `media.size` is the stored original; the generated WebP sizes and
 * the size before compression come from the custom properties written by
 * App\Support\MediaSizes. Albums and uploaders count only photos that are
 * not in the trash; a photo in several albums counts for each of them.
 */
class LibraryStats
{
    public const CACHE_SECONDS = 600;

    /**
     * @return array{albums: int, photos: int, users: int, groups: int, bytes: int}
     */
    public static function totals(): array
    {
        return Cache::remember('statistics.totals', self::CACHE_SECONDS, fn () => [
            'albums' => Album::query()->whereNull('system_album')->count(),
            'photos' => Photo::count(),
            'users' => User::count(),
            'groups' => Group::count(),
            'bytes' => (int) self::originals()->sum(DB::raw('media.size + '.self::generatedSql())),
        ]);
    }

    /**
     * Disk space of all photos, including those in the trash.
     *
     * saved: bytes compression saved where the size before it is known;
     * unknownSavings: compressed photos without that size (compressed
     * before sizes were recorded); pendingSizes: photos whose generated
     * sizes are still being read (MediaSizes::fillMissing()).
     *
     * @return array{originals: int, generated: int, total: int, photos: int, saved: int, unknownSavings: int, pendingSizes: int}
     */
    public static function storage(): array
    {
        return Cache::remember('statistics.storage', self::CACHE_SECONDS, function (): array {
            $original = self::json('media.custom_properties->'.MediaSizes::ORIGINAL);
            $compressed = self::json('media.custom_properties->'.PhotoCompression::PROPERTY);
            $sizes = self::json('media.custom_properties->'.MediaSizes::CONVERSIONS);

            $row = self::originals()
                ->selectRaw('COUNT(*) as photos, COALESCE(SUM(media.size), 0) as originals')
                ->selectRaw('COALESCE(SUM('.self::generatedSql().'), 0) as generated')
                ->selectRaw("COALESCE(SUM(CASE WHEN {$original} IS NOT NULL THEN {$original} - media.size ELSE 0 END), 0) as saved")
                ->selectRaw("SUM(CASE WHEN {$compressed} IS NOT NULL AND {$original} IS NULL THEN 1 ELSE 0 END) as unknown_savings")
                ->selectRaw("SUM(CASE WHEN {$sizes} IS NULL THEN 1 ELSE 0 END) as pending_sizes")
                ->first();

            return [
                'originals' => (int) $row->originals,
                'generated' => (int) $row->generated,
                'total' => (int) $row->originals + (int) $row->generated,
                'photos' => (int) $row->photos,
                'saved' => max(0, (int) $row->saved),
                'unknownSavings' => (int) $row->unknown_savings,
                'pendingSizes' => (int) $row->pending_sizes,
            ];
        });
    }

    /**
     * The albums taking the most space (originals and generated files).
     *
     * @return list<array{id: int, title: string, photos: int, bytes: int}>
     */
    public static function storageByAlbum(int $limit = 10): array
    {
        return Cache::remember("statistics.storage-albums.{$limit}", self::CACHE_SECONDS, fn () => self::originals()
            ->join('photos', 'photos.id', '=', 'media.model_id')
            ->join('album_photo', 'album_photo.photo_id', '=', 'photos.id')
            ->join('albums', 'albums.id', '=', 'album_photo.album_id')
            ->whereNull('photos.deleted_at')
            ->whereNull('albums.deleted_at')
            ->whereNull('albums.system_album')
            ->groupBy('albums.id', 'albums.title')
            ->selectRaw('albums.id, albums.title, COUNT(*) as photos, SUM(media.size + '.self::generatedSql().') as bytes')
            ->orderByDesc('bytes')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => ['id' => (int) $row->id, 'title' => (string) $row->title, 'photos' => (int) $row->photos, 'bytes' => (int) $row->bytes])
            ->all());
    }

    /**
     * The people whose uploads take the most space; uploads of deleted
     * accounts are one row with id null.
     *
     * @return list<array{id: ?int, name: ?string, photos: int, bytes: int}>
     */
    public static function storageByUploader(int $limit = 10): array
    {
        return Cache::remember("statistics.storage-uploaders.{$limit}", self::CACHE_SECONDS, fn () => self::originals()
            ->join('photos', 'photos.id', '=', 'media.model_id')
            ->leftJoin('users', 'users.id', '=', 'photos.uploader_id')
            ->whereNull('photos.deleted_at')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.id, users.name, COUNT(*) as photos, SUM(media.size + '.self::generatedSql().') as bytes')
            ->orderByDesc('bytes')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => ['id' => $row->id === null ? null : (int) $row->id, 'name' => $row->name, 'photos' => (int) $row->photos, 'bytes' => (int) $row->bytes])
            ->all());
    }

    /**
     * Photos, albums and members added per bucket of $range, with the
     * running totals (everything that exists now, by when it was added).
     *
     * @return list<array{start: CarbonImmutable, photos: int, albums: int, members: int, totalPhotos: int, totalAlbums: int, totalMembers: int}>
     */
    public static function growth(StatisticsRange $range): array
    {
        $start = $range->start();
        $buckets = $range->buckets();

        $counts = Cache::remember("statistics.growth.{$range->value}.{$start->toDateString()}", self::CACHE_SECONDS, function () use ($start): array {
            $daily = fn (QueryBuilder $query) => $query
                ->where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as n')
                ->groupBy('day')
                ->pluck('n', 'day')
                ->map(fn ($n) => (int) $n)
                ->all();

            $albums = fn () => DB::table('albums')->whereNull('deleted_at')->whereNull('system_album');

            return [
                'photos' => $daily(DB::table('photos')->whereNull('deleted_at')),
                'albums' => $daily($albums()),
                'members' => $daily(DB::table('users')),
                'before' => [
                    'photos' => DB::table('photos')->whereNull('deleted_at')->where('created_at', '<', $start)->count(),
                    'albums' => $albums()->where('created_at', '<', $start)->count(),
                    'members' => DB::table('users')->where('created_at', '<', $start)->count(),
                ],
            ];
        });

        $series = [];
        foreach ($buckets as $bucket) {
            $series[$bucket->toDateString()] = ['start' => $bucket, 'photos' => 0, 'albums' => 0, 'members' => 0];
        }

        foreach (['photos', 'albums', 'members'] as $kind) {
            foreach ($counts[$kind] as $day => $n) {
                $key = $range->bucketOf(CarbonImmutable::parse($day))->toDateString();
                if (isset($series[$key])) {
                    $series[$key][$kind] += $n;
                }
            }
        }

        $totals = $counts['before'];
        foreach ($series as $key => $bucket) {
            foreach (['photos', 'albums', 'members'] as $kind) {
                $totals[$kind] += $bucket[$kind];
            }
            $series[$key] += ['totalPhotos' => $totals['photos'], 'totalAlbums' => $totals['albums'], 'totalMembers' => $totals['members']];
        }

        return array_values($series);
    }

    /** Media rows of photo originals (also used by App\Support\StorageQuota). */
    public static function originals(): QueryBuilder
    {
        return DB::table('media')
            ->where('media.model_type', (new Photo)->getMorphClass())
            ->where('media.collection_name', Photo::COLLECTION);
    }

    /** SQL for the bytes of one photo's generated files (0 where not recorded). */
    public static function generatedSql(): string
    {
        return '('.implode(' + ', array_map(
            fn (string $conversion) => 'COALESCE('.self::json('media.custom_properties->'.MediaSizes::CONVERSIONS.'->'.$conversion).', 0)',
            array_keys(Photo::CONVERSION_SIZES),
        )).')';
    }

    /** A JSON path ("column->key->key") in the connection's own SQL. */
    private static function json(string $path): string
    {
        return DB::connection()->getQueryGrammar()->wrap($path);
    }
}
