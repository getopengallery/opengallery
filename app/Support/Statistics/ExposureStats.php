<?php

namespace App\Support\Statistics;

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\AlbumGuest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who can reach what: albums by visibility, and how much anonymous
 * visitors can see. "Open to everyone" mirrors Album::listableFor(null):
 * public, not expired, and public albums turned on in the settings; a
 * password still keeps the photos of such an album locked. Albums in the
 * trash and personal system albums are left out. Cached for CACHE_SECONDS.
 */
class ExposureStats
{
    public const CACHE_SECONDS = 600;

    /**
     * @return array{
     *     albums: int,
     *     byVisibility: array<string, int>,
     *     withPassword: int,
     *     expired: int,
     *     publicAlbums: bool,
     *     openAlbums: int,
     *     openPhotos: int,
     *     lockedOpenAlbums: int,
     *     guests: int,
     *     guestAlbums: int,
     * }
     */
    public static function overview(): array
    {
        $publicAlbums = (bool) config('gallery.public_albums_enabled');

        return Cache::remember('statistics.exposure.'.($publicAlbums ? 'public' : 'members'), self::CACHE_SECONDS, function () use ($publicAlbums): array {
            $albums = fn () => Album::query()->whereNull('system_album');

            $byVisibility = $albums()
                ->selectRaw('visibility, COUNT(*) as n')
                ->groupBy('visibility')
                ->toBase()
                ->pluck('n', 'visibility')
                ->map(fn ($n) => (int) $n)
                ->all();

            $open = fn () => $albums()->listableFor(null);
            $openUnlocked = fn () => $open()->whereNull('password');

            return [
                'albums' => array_sum($byVisibility),
                'byVisibility' => collect(Visibility::cases())->mapWithKeys(fn (Visibility $visibility) => [$visibility->value => $byVisibility[$visibility->value] ?? 0])->all(),
                'withPassword' => $albums()->whereNotNull('password')->count(),
                'expired' => $albums()->whereNotNull('access_expires_at')->where('access_expires_at', '<=', now())->count(),
                'publicAlbums' => $publicAlbums,
                'openAlbums' => $open()->count(),
                'lockedOpenAlbums' => $open()->whereNotNull('password')->count(),
                'openPhotos' => DB::table('album_photo')
                    ->join('photos', 'photos.id', '=', 'album_photo.photo_id')
                    ->whereNull('photos.deleted_at')
                    ->whereIn('album_photo.album_id', $openUnlocked()->select('albums.id'))
                    ->distinct()
                    ->count('album_photo.photo_id'),
                'guests' => AlbumGuest::query()->whereHas('album', fn ($query) => $query->whereNull('system_album'))->count(),
                'guestAlbums' => AlbumGuest::query()->whereHas('album', fn ($query) => $query->whereNull('system_album'))->distinct()->count('album_id'),
            ];
        });
    }
}
