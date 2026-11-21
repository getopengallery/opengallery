<?php

namespace App\Support\Dashboard;

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queries behind the album and photo widgets of the admin dashboard. Every
 * method takes the viewer and returns only what they may manage or see
 * (owner, Album::manageableBy(), Photo::visibleTo()).
 */
class AlbumStats
{
    /** Albums whose share link (end date) runs out within this many days count as "expires soon". */
    public const EXPIRY_WARNING_DAYS = 14;

    /**
     * $user's own albums (not "Unsorted uploads" or "Page images"), latest changed first.
     *
     * @return Collection<int, Album>
     */
    public static function ownAlbums(User $user, int $limit = 5): Collection
    {
        return Album::query()
            ->whereBelongsTo($user, 'owner')
            ->whereNull('system_album')
            ->withCount('photos')
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * $user's "Unsorted uploads" album and how many photos wait in it, if any do.
     *
     * @return array{album: Album, photos: int}|null
     */
    public static function inbox(User $user): ?array
    {
        $album = Album::query()->whereBelongsTo($user, 'owner')->where('system_album', Album::INBOX)->withCount('photos')->first();

        return $album && $album->photos_count > 0 ? ['album' => $album, 'photos' => $album->photos_count] : null;
    }

    /**
     * Albums $viewer manages that need a look: empty, without a cover, or
     * whose end date (for people other than the owner) has passed or is near.
     *
     * @return array{albums: Collection<int, Album>, total: int}
     */
    public static function needingAttention(User $viewer, int $limit = 6): array
    {
        $query = Album::query()
            ->manageableBy($viewer)
            ->whereNull('system_album')
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('photos')
                ->orWhereNull('cover_photo_id')
                ->orWhere(fn (Builder $expiring) => $expiring
                    ->where('visibility', '!=', Visibility::Private)
                    ->whereNotNull('access_expires_at')
                    ->where('access_expires_at', '<=', now()->addDays(self::EXPIRY_WARNING_DAYS))));

        return [
            'albums' => (clone $query)->with('owner')->withCount('photos')->latest('updated_at')->limit($limit)->get(),
            'total' => $query->count(),
        ];
    }

    /**
     * Why an album needs a look, as English keys translated where shown:
     * "Empty", "No cover", "Link expired", "Link expires soon".
     *
     * @return list<string>
     */
    public static function issues(Album $album): array
    {
        $expires = $album->visibility !== Visibility::Private ? $album->access_expires_at : null;

        return array_values(array_filter([
            ($album->photos_count ?? 1) === 0 ? 'Empty' : null,
            ($album->photos_count ?? 0) > 0 && $album->cover_photo_id === null ? 'No cover' : null,
            $expires?->isPast() ? 'Link expired' : null,
            $expires && ! $expires->isPast() && $expires->lte(now()->addDays(self::EXPIRY_WARNING_DAYS)) ? 'Link expires soon' : null,
        ]));
    }

    /**
     * $user's latest uploads that are not in the trash, and how many they uploaded in the last 7 days.
     *
     * @return array{photos: Collection<int, Photo>, thisWeek: int}
     */
    public static function recentUploads(User $user, int $limit = 8): array
    {
        $own = fn () => Photo::query()->where('uploader_id', $user->id);

        return [
            'photos' => $own()->with('media')->latest('created_at')->latest('id')->limit($limit)->get(),
            'thisWeek' => $own()->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }
}
