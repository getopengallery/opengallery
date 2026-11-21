<?php

namespace App\Policies;

use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use App\Support\AlbumCollaboration;

/**
 * Collaborators never pass update(): it also drives PhotoPolicy (edit, trash),
 * trashed photos, original downloads and the admin form. What a role allows
 * has its own abilities below. Super admins pass everything (Gate::before), so
 * whoever acts on an album also checks Album::acceptsContributions().
 */
class AlbumPolicy
{
    public function viewAny(?User $user): bool
    {
        return true; // site listings use Album::listableFor(), the admin Album::workableBy()
    }

    public function view(?User $user, Album $album): bool
    {
        return $album->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CreateAlbums->value);
    }

    public function update(User $user, Album $album): bool
    {
        return $user->id === $album->owner_id || $user->can(Permission::EditAnyAlbum->value);
    }

    /** Also whoever created this sub-album as a collaborator, while they work on the album it is in. */
    public function delete(User $user, Album $album): bool
    {
        return $user->id === $album->owner_id
            || $user->can(Permission::DeleteAnyAlbum->value)
            || AlbumCollaboration::createdThroughRole($album, $user);
    }

    public function restore(User $user, Album $album): bool
    {
        return $this->delete($user, $album);
    }

    /** Through App\Support\AlbumDeletion, which keeps sub-albums and photos in place. */
    public function forceDelete(User $user, Album $album): bool
    {
        return $this->delete($user, $album);
    }

    /**
     * Opening the album in the admin. What the page then offers is decided by
     * the abilities below, so this is not a right to change anything.
     */
    public function workOn(User $user, Album $album): bool
    {
        return $this->update($user, $album) || $album->collaboratorRole($user) !== null;
    }

    /** Uploading into an album needs "upload photos" and the right to edit it, or a collaborator role. */
    public function upload(User $user, Album $album): bool
    {
        return ($user->can(Permission::UploadPhotos->value) && $this->update($user, $album))
            || $this->hasRole($user, $album, CollaboratorRole::Contributor);
    }

    public function createSubAlbum(User $user, Album $album): bool
    {
        return $album->acceptsContributions() && (
            ($user->can(Permission::CreateAlbums->value) && $this->update($user, $album))
            || $this->hasRole($user, $album, CollaboratorRole::Organizer)
        );
    }

    /**
     * Taking photos out of this album (never trashing them). Contributors only
     * their own uploads: pass $photo to check one.
     */
    public function removePhotos(User $user, Album $album, ?Photo $photo = null): bool
    {
        if (! $album->acceptsContributions()) {
            return false;
        }

        if ($this->update($user, $album) || $this->hasRole($user, $album, CollaboratorRole::Organizer)) {
            return true;
        }

        return $this->hasRole($user, $album, CollaboratorRole::Contributor)
            && ($photo === null || $photo->uploader_id === $user->id);
    }

    /** The album form. For Editors it saves an explicit list of fields, never owner or parent. */
    public function editSettings(User $user, Album $album): bool
    {
        return $album->acceptsContributions()
            && ($this->update($user, $album) || $this->hasRole($user, $album, CollaboratorRole::Editor));
    }

    /** Searching people and groups to share with; other Editors only see and remove existing shares. */
    public function searchDirectory(User $user, Album $album): bool
    {
        return $this->editSettings($user, $album)
            && ($user->can(Permission::InviteCollaborators->value) || Album::managesEveryAlbum($user));
    }

    /** Editors cannot do this, so nobody can raise their own role. */
    public function manageCollaborators(User $user, Album $album): bool
    {
        return $album->acceptsContributions() && (
            ($user->id === $album->owner_id && $user->can(Permission::InviteCollaborators->value))
            || $user->can(Permission::EditAnyAlbum->value)
        );
    }

    /** The collaborator list: the owner, staff and collaborators. */
    public function viewCollaborators(User $user, Album $album): bool
    {
        return $user->id === $album->owner_id
            || Album::managesEveryAlbum($user)
            || $album->collaboratorRole($user) !== null;
    }

    /** Leaving removes a direct role; access through a group or a parent album stays. */
    public function leave(User $user, Album $album): bool
    {
        return $album->acceptsContributions()
            && $user->id !== $album->owner_id
            && AlbumCollaboration::hasDirectRole($album, $user);
    }

    private function hasRole(User $user, Album $album, CollaboratorRole $atLeast): bool
    {
        return $album->collaboratorRole($user)?->atLeast($atLeast) ?? false;
    }
}
