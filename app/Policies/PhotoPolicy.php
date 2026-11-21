<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;

/**
 * Photos inherit access from their albums (a photo can be in several):
 * visible if any of its albums is visible; editable by the uploader or by
 * anyone who manages one of its albums. Trashing removes the photo from
 * every album, so it needs the uploader or someone who manages all of them.
 * Removing a photo from a single album is an album edit (AlbumPolicy::update).
 */
class PhotoPolicy
{
    public function __construct(private AlbumPolicy $albums) {}

    /** The media library in the admin; rows are limited by Photo::visibleTo(). */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::AccessAdmin->value);
    }

    public function view(?User $user, Photo $photo): bool
    {
        return ($user && $user->id === $photo->uploader_id)
            || $photo->activeAlbums()->contains(fn (Album $album) => $this->albums->view($user, $album));
    }

    public function update(User $user, Photo $photo): bool
    {
        return $user->id === $photo->uploader_id
            || $photo->albums->contains(fn (Album $album) => $this->albums->update($user, $album));
    }

    public function delete(User $user, Photo $photo): bool
    {
        return $user->id === $photo->uploader_id
            || ($photo->albums->isNotEmpty() && $photo->albums->every(fn (Album $album) => $this->albums->update($user, $album)));
    }

    public function restore(User $user, Photo $photo): bool
    {
        return $this->delete($user, $photo);
    }

    public function forceDelete(User $user, Photo $photo): bool
    {
        return $this->delete($user, $photo);
    }

    /** Bulk actions check every selected record individually as well. */
    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
