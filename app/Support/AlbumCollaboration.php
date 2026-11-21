<?php

namespace App\Support;

use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who works on which album. A role applies to the album and everything below
 * it; a person's effective role is the highest of their own roles and those
 * of their current groups, on the album and its ancestors.
 *
 * Roles are worked out once per request for all albums (RequestMemo, never
 * cached across requests), so checking many albums costs no extra queries.
 * AppServiceProvider forgets them whenever a role, a group's members or the
 * album tree changes.
 *
 * A role never passes AlbumPolicy::update: that check also grants photo
 * editing, the trash, original files and the admin form.
 */
class AlbumCollaboration
{
    /** RequestMemo key prefix of the resolved roles. */
    public const MEMO = 'album-collaborators.';

    /**
     * The albums $user works on, with their effective role. Trashed and
     * system albums are left out.
     *
     * @return array<int, CollaboratorRole>
     */
    public static function rolesFor(User $user): array
    {
        return self::resolved($user)['roles'];
    }

    /** @return list<int> */
    public static function albumIdsFor(User $user): array
    {
        return array_keys(self::rolesFor($user));
    }

    /** $user's effective role on $album; null for the owner, whose rights always go further. */
    public static function roleOn(Album $album, User $user): ?CollaboratorRole
    {
        if ($album->owner_id === $user->id || ! $album->acceptsContributions()) {
            return null;
        }

        return self::rolesFor($user)[$album->id] ?? null;
    }

    /** Whether $user holds a role on exactly this album (not through a group or a parent). */
    public static function hasDirectRole(Album $album, User $user): bool
    {
        return in_array($album->id, self::resolved($user)['direct'], true);
    }

    /**
     * Effective roles of many people on one album, in two queries.
     *
     * @param  list<int>  $userIds
     * @return array<int, CollaboratorRole>
     */
    public static function rolesOn(Album $album, array $userIds): array
    {
        if ($userIds === [] || ! $album->acceptsContributions()) {
            return [];
        }

        $chain = self::selfAndAncestorIds($album);
        $rows = DB::table('album_collaborators')
            ->whereIn('album_id', $chain)
            ->whereIntegerInRaw('user_id', $userIds)
            ->get(['user_id', 'role'])
            ->concat(DB::table('album_group_collaborators')
                ->join('group_user', 'group_user.group_id', '=', 'album_group_collaborators.group_id')
                ->whereIn('album_group_collaborators.album_id', $chain)
                ->whereIntegerInRaw('group_user.user_id', $userIds)
                ->get(['group_user.user_id', 'album_group_collaborators.role']));

        $roles = [];

        foreach ($rows as $row) {
            if ((int) $row->user_id !== $album->owner_id) {
                $roles[(int) $row->user_id] = CollaboratorRole::highest($roles[(int) $row->user_id] ?? null, CollaboratorRole::from($row->role));
            }
        }

        return $roles;
    }

    /**
     * This album and the albums above it, nearest first.
     *
     * @return list<int>
     */
    public static function selfAndAncestorIds(Album $album): array
    {
        $ids = [$album->id];
        $parentId = $album->parent_id;

        while ($parentId !== null && ! in_array((int) $parentId, $ids, true)) {
            $ids[] = (int) $parentId;
            $parentId = Album::withTrashed()->whereKey($parentId)->value('parent_id');
        }

        return $ids;
    }

    /**
     * What still gives $user access to $album apart from a direct role on
     * it: their groups, and parent albums they hold a role on.
     *
     * @return array{groups: list<string>, albums: list<string>}
     */
    public static function otherAccess(Album $album, User $user): array
    {
        $chain = self::selfAndAncestorIds($album);

        return [
            'groups' => DB::table('album_group_collaborators')
                ->join('group_user', 'group_user.group_id', '=', 'album_group_collaborators.group_id')
                ->join('groups', 'groups.id', '=', 'album_group_collaborators.group_id')
                ->whereIn('album_group_collaborators.album_id', $chain)
                ->where('group_user.user_id', $user->id)
                ->orderBy('groups.name')
                ->distinct()
                ->pluck('groups.name')
                ->all(),
            'albums' => DB::table('album_collaborators')
                ->join('albums', 'albums.id', '=', 'album_collaborators.album_id')
                ->whereIn('album_collaborators.album_id', array_values(array_diff($chain, [$album->id])))
                ->where('album_collaborators.user_id', $user->id)
                ->whereNull('albums.deleted_at')
                ->pluck('albums.title')
                ->all(),
        ];
    }

    /** Whether $user reaches this album only through a role, not as its owner or staff. */
    public static function worksThroughRole(Album $album, User $user): bool
    {
        return $album->collaboratorRole($user) !== null && ! $user->can('update', $album);
    }

    /**
     * Whether $user created this sub-album as a collaborator and still works
     * on the album it is in. They may then trash, restore and delete it, also
     * while it is in the trash. Moving it out of that album ends this.
     */
    public static function createdThroughRole(Album $album, User $user): bool
    {
        return (int) $album->created_by === $user->id
            && $album->owner_id !== $user->id
            && $album->parent_id !== null
            && ! $album->isSystem()
            && isset(self::rolesFor($user)[(int) $album->parent_id]);
    }

    /**
     * The album the media picker must stay within for $user, or null for the
     * whole library: people working through a role, and people without
     * "Open the admin area", only get this album's photos and their own uploads.
     */
    public static function pickerAlbumId(?Album $album, User $user): ?int
    {
        return $album && (self::worksThroughRole($album, $user) || ! $user->can(Permission::AccessAdmin->value))
            ? $album->id
            : null;
    }

    /** Whether $user can upload to $album only because of a role (not as its owner or staff). */
    public static function uploadsOnlyThroughRole(Album $album, User $user): bool
    {
        return ! ($user->can(Permission::UploadPhotos->value) && $user->can('update', $album));
    }

    /**
     * Whether $user may put an existing photo into $album. Adding a photo to a
     * more open album publishes it, so someone who can upload here only
     * through a role may add nothing but their own uploads.
     */
    public static function mayAddPhoto(Album $album, Photo $photo, User $user): bool
    {
        return $user->can('upload', $album)
            && (self::uploadsOnlyThroughRole($album, $user) ? $photo->uploader_id === $user->id : $user->can('update', $photo));
    }

    /**
     * The photos out of $ids that $user may put into $album.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function addablePhotoIds(Album $album, User $user, array $ids): array
    {
        if (! $user->can('upload', $album)) {
            return [];
        }

        $allowed = (self::uploadsOnlyThroughRole($album, $user)
            ? Photo::query()->where('uploader_id', $user->id)
            : Photo::reusableBy($user))->whereKey($ids)->pluck('id')->all();

        return array_values(array_filter($ids, fn (int $id) => in_array($id, $allowed, true)));
    }

    /**
     * Keeps uploads by collaborators when albums are deleted for good: photos
     * that would be left in no album go to the "Unsorted uploads" of the
     * person who uploaded them, if they hold a role on one of those albums.
     * Call it before the albums are deleted, while the tree and roles exist.
     *
     * @param  list<int>  $albumIds
     * @return int how many photos were moved
     */
    public static function keepCollaboratorUploads(array $albumIds): int
    {
        $photos = Photo::withTrashed()
            ->whereKey(AlbumDeletion::photosOnlyIn($albumIds))
            ->whereNotNull('uploader_id')
            ->with(['uploader', 'albums'])
            ->get();

        $moved = 0;

        foreach ($photos->groupBy('uploader_id') as $uploaded) {
            $uploader = $uploaded->first()->uploader;

            if (! $uploader) {
                continue;
            }

            $roles = self::resolve($uploader->id, withTrashed: true)['roles'];
            $ids = $uploaded
                ->filter(fn (Photo $photo) => $photo->albums->contains(fn (Album $album) => isset($roles[$album->id]) && $album->owner_id !== $uploader->id))
                ->modelKeys();

            if ($ids !== []) {
                $inbox = Album::inbox($uploader);
                $inbox->addPhotos($ids);
                ActivityLog::photosAdded($inbox, count($ids));
                $moved += count($ids);
            }
        }

        return $moved;
    }

    /**
     * @return array{roles: array<int, CollaboratorRole>, direct: list<int>}
     */
    private static function resolved(User $user): array
    {
        return RequestMemo::instance()->remember(self::MEMO.$user->id, fn () => self::resolve($user->id));
    }

    /**
     * Four queries however many albums and groups: the person's direct and
     * group roles, where those albums are, and everything below them.
     *
     * @return array{roles: array<int, CollaboratorRole>, direct: list<int>}
     */
    private static function resolve(int $userId, bool $withTrashed = false): array
    {
        $direct = DB::table('album_collaborators')->where('user_id', $userId)->pluck('role', 'album_id')->all();
        $throughGroups = DB::table('album_group_collaborators')
            ->join('group_user', 'group_user.group_id', '=', 'album_group_collaborators.group_id')
            ->where('group_user.user_id', $userId)
            ->get(['album_group_collaborators.album_id', 'album_group_collaborators.role']);

        /** @var array<int, CollaboratorRole> $granted */
        $granted = [];

        foreach ($direct as $albumId => $role) {
            $granted[(int) $albumId] = CollaboratorRole::from($role);
        }

        foreach ($throughGroups as $row) {
            $granted[(int) $row->album_id] = CollaboratorRole::highest($granted[(int) $row->album_id] ?? null, CollaboratorRole::from($row->role));
        }

        if ($granted === []) {
            return ['roles' => [], 'direct' => []];
        }

        // Albums without a tree path only count themselves: an empty prefix would match everything.
        $paths = array_filter(DB::table('albums')->whereIn('id', array_keys($granted))->pluck('tree_path')->all(), 'filled');

        $rows = DB::table('albums')
            ->where(function ($query) use ($granted, $paths) {
                $query->whereIn('id', array_keys($granted));

                foreach (self::topmost($paths) as $path) {
                    $query->orWhere('tree_path', 'like', $path.'%');
                }
            })
            ->get(['id', 'tree_path', 'deleted_at', 'system_album'])
            ->sort(fn (object $a, object $b) => strcmp((string) $a->tree_path, (string) $b->tree_path));

        $roles = [];
        $ancestors = [];

        // In path order every album comes right after its ancestors, so a stack of the open branches is enough.
        foreach ($rows as $row) {
            $path = (string) $row->tree_path;

            while ($ancestors !== [] && ! str_starts_with($path, $ancestors[array_key_last($ancestors)]['path'])) {
                array_pop($ancestors);
            }

            $inherited = $ancestors === [] ? null : $ancestors[array_key_last($ancestors)]['role'];
            $role = CollaboratorRole::highest($inherited, $granted[(int) $row->id] ?? null);

            if ($path !== '') {
                $ancestors[] = ['path' => $path, 'role' => $role];
            }

            if ($role !== null && $row->system_album === null && ($withTrashed || $row->deleted_at === null)) {
                $roles[(int) $row->id] = $role;
            }
        }

        return ['roles' => $roles, 'direct' => array_map('intval', array_keys($direct))];
    }

    /**
     * The paths that are not below another one in the list.
     *
     * @param  array<int, string>  $paths
     * @return list<string>
     */
    private static function topmost(array $paths): array
    {
        sort($paths, SORT_STRING);
        $topmost = [];

        foreach ($paths as $path) {
            if ($topmost === [] || ! str_starts_with($path, $topmost[array_key_last($topmost)])) {
                $topmost[] = $path;
            }
        }

        return $topmost;
    }
}
