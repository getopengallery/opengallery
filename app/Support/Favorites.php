<?php

namespace App\Support;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Each logged-in person's favourite photos: the heart in the lightbox and
 * their private "Favorites" (route favorites.index), while Settings → Photos
 * allows it.
 *
 * Favourites live in their own table (photo_favorites), not in an album:
 * liking someone else's photo must never give rights to it, and a photo only
 * shows in Favorites while the person may still see it through one of its
 * albums (AlbumAccess::canViewPhoto). Photos in the trash are left out;
 * deleting a photo for good removes it from everyone's Favorites.
 */
class Favorites
{
    public static function enabledFor(?User $user): bool
    {
        return $user !== null && SiteSettings::favoritesEnabled();
    }

    /** @return array<int, true> ids of the photos $user liked, as keys (once per request) */
    public static function ids(User $user): array
    {
        return RequestMemo::instance()->remember(
            'favorites.'.$user->id,
            fn () => array_fill_keys($user->favoritePhotos()->pluck('photos.id')->all(), true),
        );
    }

    public static function has(User $user, Photo $photo): bool
    {
        return isset(self::ids($user)[$photo->id]);
    }

    /** Likes or unlikes $photo for $user; returns whether it is a favourite now. */
    public static function toggle(User $user, Photo $photo): bool
    {
        $favorite = ! self::has($user, $photo);

        if ($favorite) {
            $user->favoritePhotos()->syncWithoutDetaching([$photo->id]);
        } else {
            $user->favoritePhotos()->detach($photo->id);
        }

        RequestMemo::instance()->forget('favorites.'.$user->id);

        return $favorite;
    }

    /**
     * One page of the favourites $user may still see, the most recently liked first.
     *
     * @return LengthAwarePaginator<int, Photo>
     */
    public static function page(User $user, int $perPage): LengthAwarePaginator
    {
        $photos = $user->favoritePhotos()
            ->with(['media', 'albums'])
            ->orderByPivot('created_at', 'desc')
            ->orderByPivot('id', 'desc')
            ->get()
            ->filter(fn (Photo $photo) => AlbumAccess::canViewPhoto($user, $photo))
            ->values();

        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $photos->forPage($page, $perPage)->values(),
            $photos->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
}
