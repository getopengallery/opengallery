<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deletes user accounts and decides what happens to the albums they own.
 * The database only clears `albums.owner_id`, so every place that deletes a
 * user must go through here:
 *
 *  - the admin: albums go to a new owner, or to the trash without one
 *    (deleteKeepingAlbums);
 *  - the profile: people delete their own albums and photos with their account,
 *    after confirming it (deleteWithAllData).
 *
 * Personal system albums: with a new owner, "Unsorted uploads" and "Page
 * images" are merged into the new owner's own. Otherwise "Unsorted uploads" is
 * treated like any other album, while "Page images" (its photos are shown on
 * pages) is never trashed or deleted with a user; it is left without an owner.
 */
class AccountDeletion
{
    /**
     * Albums the given people own, including the trash and "Unsorted uploads",
     * without "Page images".
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Album>
     */
    public static function ownedAlbums(array $userIds): Collection
    {
        return Album::withTrashed()
            ->whereIn('owner_id', $userIds)
            ->where(fn ($query) => $query->whereNull('system_album')->orWhere('system_album', '!=', Album::PAGE_IMAGES))
            ->orderBy('tree_path')
            ->get();
    }

    /** Gives the user's albums to $newOwner, or moves them to the trash, then deletes the user. */
    public static function deleteKeepingAlbums(User $user, ?User $newOwner): void
    {
        if ($newOwner?->is($user)) {
            throw new InvalidArgumentException('A deleted user cannot receive their own albums.');
        }

        DB::transaction(fn () => ActivityLog::batch(function () use ($user, $newOwner): void {
            foreach (Album::withTrashed()->whereBelongsTo($user, 'owner')->get() as $album) {
                if ($newOwner && $album->isSystem()) {
                    self::mergeSystemAlbum($album, $newOwner);
                } elseif ($newOwner) {
                    $album->update(['owner_id' => $newOwner->id]);
                } elseif ($album->system_album !== Album::PAGE_IMAGES && ! $album->trashed()) {
                    $album->delete();
                }
            }

            $user->delete();
        }));
    }

    /**
     * Deletes the user with their albums and every photo left in no album
     * because of it, permanently ("Unsorted uploads" included, "Page images"
     * kept for the pages). Photos that are also in other people's albums stay
     * there, collaborators keep their uploads in their own "Unsorted uploads",
     * and other people's albums inside theirs move up a level.
     */
    public static function deleteWithAllData(User $user): void
    {
        DB::transaction(fn () => ActivityLog::batch(function () use ($user): void {
            $albumIds = self::ownedAlbums([$user->id])->modelKeys();

            // What collaborators uploaded is theirs: it goes to their "Unsorted uploads".
            AlbumCollaboration::keepCollaboratorUploads($albumIds);

            $photoIds = DB::table('album_photo')->whereIn('album_id', $albumIds)->pluck('photo_id')
                ->merge(Photo::withTrashed()->where('uploader_id', $user->id)->pluck('id'))
                ->unique()
                ->all();

            Album::withTrashed()->whereIn('parent_id', $albumIds)->whereKeyNot($albumIds)->get()
                ->each(fn (Album $child) => $child->update(['parent_id' => self::survivingAncestor($child->parent_id, $albumIds)]));

            Album::withTrashed()->whereKey($albumIds)->get()->each->forceDelete();

            Photo::withTrashed()->whereKey($photoIds)->with('albums')->get()
                ->filter(fn (Photo $photo) => $photo->albums->isEmpty())
                ->each->forceDelete();

            $user->delete();
        }));
    }

    /** Moves the photos of a deleted user's system album into $newOwner's own album of that kind. */
    private static function mergeSystemAlbum(Album $album, User $newOwner): void
    {
        $target = $album->system_album === Album::INBOX ? Album::inbox($newOwner) : Album::pageImages($newOwner);
        $photoIds = DB::table('album_photo')->where('album_id', $album->id)->orderBy('sort_order')->pluck('photo_id')->all();

        if ($photoIds !== []) {
            $target->addPhotos($photoIds);
            ActivityLog::photosAdded($target, count($photoIds));
        }

        $album->forceDelete();
    }

    /** @param  list<int>  $deletedIds */
    private static function survivingAncestor(?int $parentId, array $deletedIds): ?int
    {
        while ($parentId !== null && in_array($parentId, $deletedIds, true)) {
            $next = Album::withTrashed()->whereKey($parentId)->value('parent_id');
            $parentId = $next === null ? null : (int) $next;
        }

        return $parentId;
    }
}
