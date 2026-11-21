<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Page;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes albums permanently without breaking the rule that every photo stays
 * in at least one album:
 *
 *  - albums inside it move up to its parent (with their tree path);
 *  - uploads by its collaborators move to their own "Unsorted uploads";
 *  - other photos that are in no other album move to the album owner's "Unsorted
 *    uploads" (or the deleting person's for albums without an owner), or are
 *    deleted permanently on request, except photos used on a page.
 */
class AlbumDeletion
{
    /**
     * Photos (trash included) in these albums and in no album outside them.
     *
     * @param  list<int>  $albumIds
     * @return list<int>
     */
    public static function photosOnlyIn(array $albumIds): array
    {
        return Photo::withTrashed()
            ->whereHas('albums', fn ($query) => $query->whereKey($albumIds))
            ->whereDoesntHave('albums', fn ($query) => $query->whereKeyNot($albumIds))
            ->pluck('id')
            ->all();
    }

    public static function forceDelete(Album $album, bool $deletePhotos, User $actor): void
    {
        DB::transaction(fn () => ActivityLog::batch(function () use ($album, $deletePhotos, $actor): void {
            // In a bulk deletion an earlier album may have changed this one's parent.
            $album->refresh();
            AlbumCollaboration::keepCollaboratorUploads([$album->id]);
            $orphans = self::photosOnlyIn([$album->id]);

            Album::withTrashed()->where('parent_id', $album->id)->get()
                ->each(fn (Album $child) => $child->update(['parent_id' => $album->parent_id]));

            $album->forceDelete();
            AlbumPreviewImage::forget($album);

            $keep = $deletePhotos ? array_values(array_intersect($orphans, Page::usedPhotoIds())) : $orphans;

            Photo::withTrashed()->whereKey(array_diff($orphans, $keep))->get()->each->forceDelete();

            if ($keep !== []) {
                $inbox = Album::inbox($album->owner ?? $actor);
                $inbox->addPhotos($keep);
                ActivityLog::photosAdded($inbox, count($keep));
            }
        }));
    }
}
