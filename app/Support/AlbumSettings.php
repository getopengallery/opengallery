<?php

namespace App\Support;

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;

/**
 * The save rules for an album's settings in the admin form (CreateAlbum,
 * EditAlbum), for owners, staff and Editors.
 */
class AlbumSettings
{
    /**
     * Everything an Editor may change. `Album` also has `owner_id` and
     * `parent_id` in `$fillable`, so nothing else is saved for them.
     */
    public const EDITOR_FIELDS = ['title', 'description', 'cover_photo_id', 'photo_order', 'visibility', 'password', 'protect', 'access_expires_at', 'allow_downloads'];

    /**
     * Applies the "Require a password" switch and drops settings that do not
     * apply to private albums.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normaliseAccess(array $data): array
    {
        $visibility = $data['visibility'] ?? null;
        $private = ($visibility instanceof Visibility ? $visibility : Visibility::tryFrom((string) $visibility)) === Visibility::Private;

        if ($private || ! ($data['protect'] ?? false)) {
            $data['password'] = null;
        }

        if ($private) {
            $data['access_expires_at'] = null;
            $data['allow_downloads'] = false;
        }

        unset($data['protect']);

        return $data;
    }

    /**
     * Whether $editor may make this photo the album's cover. A cover from
     * elsewhere joins the album and so becomes visible to its visitors:
     * owners and staff may choose photos they may edit (Photo::reusableBy()),
     * everyone else only a photo that is already in the album or their own upload.
     */
    public static function coverAllowed(Album $album, int $photoId, User $editor): bool
    {
        if ($editor->can('update', $album)) {
            return Photo::reusableBy($editor)->whereKey($photoId)->exists();
        }

        return Photo::query()
            ->whereKey($photoId)
            ->where(fn ($query) => $query
                ->where('uploader_id', $editor->id)
                ->orWhereHas('albums', fn ($albums) => $albums->whereKey($album->id)))
            ->exists();
    }

    /**
     * Adds a cover from elsewhere to the album, so visitors can see it.
     *
     * @return bool whether the album's photos changed
     */
    public static function keepCoverInAlbum(Album $album): bool
    {
        if (! $album->cover_photo_id || $album->photos()->whereKey($album->cover_photo_id)->exists()) {
            return false;
        }

        $album->addPhotos([$album->cover_photo_id]);
        ActivityLog::photosAdded($album, 1);

        return true;
    }

    /**
     * Who the album is shared with, as keys for the combined picker.
     *
     * @return list<string>
     */
    public static function shareKeys(Album $album): array
    {
        return [
            ...$album->sharedUsers()->pluck('users.id')->map(fn ($id) => PeopleAndGroups::key(PeopleAndGroups::USER, (int) $id))->all(),
            ...$album->sharedGroups()->pluck('groups.id')->map(fn ($id) => PeopleAndGroups::key(PeopleAndGroups::GROUP, (int) $id))->all(),
        ];
    }

    /**
     * Shares the album with exactly the people and groups in $keys (the
     * combined picker). Keys that do not exist are ignored. Someone who may
     * not search the directory can only take entries off the list.
     *
     * @param  iterable<int, mixed>  $keys
     */
    public static function syncShareKeys(Album $album, iterable $keys, User $editor): void
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys, false);

        if (! $editor->can('update', $album) && ! $editor->can('searchDirectory', $album)) {
            $keys = array_values(array_intersect($keys, self::shareKeys($album)));
        }

        $split = PeopleAndGroups::split($keys);

        $album->sharedUsers()->sync($split['users']);
        $album->sharedGroups()->sync($split['groups']);
    }
}
