<?php

namespace App\Models;

use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\PhotoOrder;
use App\Enums\Visibility;
use App\Models\Pivots\AlbumCollaborator;
use App\Models\Pivots\AlbumGroup;
use App\Models\Pivots\AlbumGroupCollaborator;
use App\Models\Pivots\AlbumPhoto;
use App\Models\Pivots\AlbumUser;
use App\Support\ActivityLog;
use App\Support\AlbumCollaboration;
use App\Support\RequestMemo;
use App\Support\SiteSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['parent_id', 'owner_id', 'title', 'slug', 'description', 'visibility', 'cover_photo_id', 'sort_order', 'photo_order', 'password', 'access_expires_at', 'allow_downloads'])]
#[Hidden(['password'])]
class Album extends Model
{
    use HasFactory, SoftDeletes;

    /** Same default as the database column, so new instances have it too. */
    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'photo_order' => PhotoOrder::class,
            'password' => 'hashed',
            'access_expires_at' => 'datetime',
            'allow_downloads' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Album $album) {
            $album->slug ??= Str::slug($album->title).'-'.Str::lower(Str::random(6));
            $album->photo_order ??= SiteSettings::defaultPhotoOrder();
            // Not fillable: only ever the person creating it.
            $album->created_by = auth()->id();
        });

        static::saving(function (Album $album) {
            // Unlisted albums need a share token; other levels must not keep one around.
            if ($album->visibility === Visibility::Unlisted) {
                $album->share_token ??= Str::random(40);
            } else {
                $album->share_token = null;
            }
        });

        // The owner is never a collaborator: their rights already go further than any role.
        static::updated(function (Album $album) {
            if ($album->wasChanged('owner_id') && $album->owner_id !== null) {
                $album->collaborators()->detach($album->owner_id);
            }
        });

        // Keep the tree position (for listing albums under their parents) up to date.
        static::saved(fn (Album $album) => $album->refreshTreePath());

        // Albums inside a trashed album go to the trash with it (same time), and come
        // back with it; albums that were already in the trash before stay there.
        static::deleted(function (Album $album) {
            if (! $album->isForceDeleting()) {
                $album->descendants()->update(['deleted_at' => $album->deleted_at]);
            }
        });

        static::restoring(function (Album $album) {
            $album->descendants()->onlyTrashed()->where('deleted_at', '>=', $album->deleted_at)->update(['deleted_at' => null]);
        });
    }

    // ---- Hierarchy -------------------------------------------------------------

    /**
     * One fixed-width segment of a tree path: siblings sort by position, then
     * name; the id keeps segments unique. Paths of children start with their
     * parent's path, so ordering by tree_path lists every album under its parent.
     */
    public static function treeSegment(int $sortOrder, string $title, int $id): string
    {
        $name = str_pad(substr(Str::slug($title) ?: 'album', 0, 24), 24, ' ');

        return sprintf('%04d', max(0, min(9999, $sortOrder))).$name.sprintf('%08d', $id).'/';
    }

    /** Recomputes this album's tree path and, if it changed, its descendants'. */
    public function refreshTreePath(): void
    {
        $parentPath = $this->parent_id ? (string) static::withTrashed()->whereKey($this->parent_id)->value('tree_path') : '';
        $path = $parentPath.static::treeSegment((int) $this->sort_order, (string) $this->title, (int) $this->id);

        if ($path === $this->tree_path) {
            return;
        }

        $this->forceFill(['tree_path' => $path])->saveQuietly();

        static::withTrashed()->where('parent_id', $this->id)->get()->each(fn (Album $child) => $child->refreshTreePath());
    }

    /** 0 for top-level albums, 1 for their sub-albums, ... */
    public function depth(): int
    {
        return max(0, substr_count((string) $this->tree_path, '/') - 1);
    }

    /**
     * Albums anywhere below this one (not in the trash, unless asked for).
     *
     * @return Builder<Album>
     */
    public function descendants(): Builder
    {
        return static::query()->where('tree_path', 'like', $this->tree_path.'%')->whereKeyNot($this->id);
    }

    /** Ids of this album and all albums below it (to prevent cycles when choosing a parent). */
    public function selfAndDescendantIds(): array
    {
        return static::withTrashed()->where('tree_path', 'like', $this->tree_path.'%')->pluck('id')->all();
    }

    // ---- Access: password and end date -----------------------------------------

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    /** Changes whenever the password changes, so earlier unlocks stop working. */
    public function passwordFingerprint(): string
    {
        return substr(hash('sha256', (string) $this->password), 0, 16);
    }

    /** Changes with the share link, so sessions opened through an earlier link lose access. */
    public function shareLinkFingerprint(): string
    {
        return substr(hash('sha256', (string) $this->share_token), 0, 16);
    }

    /** True once the end date has passed: only direct access remains. */
    public function accessExpired(): bool
    {
        return $this->access_expires_at !== null && $this->access_expires_at->isPast();
    }

    /** Owner, staff who may view any album, collaborators, and people/groups a restricted album is shared with. */
    public function hasDirectAccess(?User $user): bool
    {
        return $user !== null && (
            $user->id === $this->owner_id
            || $user->can(Permission::ViewAnyAlbum->value)
            || $this->collaboratorRole($user) !== null
            || ($this->visibility === Visibility::Restricted && $this->isSharedWith($user))
        );
    }

    /** $user's effective collaborator role here (own, group or inherited); null for the owner. */
    public function collaboratorRole(?User $user): ?CollaboratorRole
    {
        return $user ? AlbumCollaboration::roleOn($this, $user) : null;
    }

    /** People can work on this album: it is neither in the trash nor a system album. */
    public function acceptsContributions(): bool
    {
        return ! $this->trashed() && ! $this->isSystem();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Album, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Album::class, 'parent_id');
    }

    /** @return HasMany<Album, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Album::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return BelongsToMany<Photo, $this> photos in this album, in album order */
    public function photos(): BelongsToMany
    {
        [$column, $direction] = ($this->photo_order ?? PhotoOrder::Manual)->orderBy();

        return $this->belongsToMany(Photo::class)
            ->using(AlbumPhoto::class)
            ->withPivot('sort_order')
            ->orderBy($column, $direction)
            ->orderBy('photos.id');
    }

    /**
     * Personal system albums (`albums.system_album`), one of each per owner, private,
     * created on first use and named in the language of that request.
     */
    public const PAGE_IMAGES = 'page-images';

    public const INBOX = 'inbox';

    /** $owner's photos uploaded while writing pages. */
    public static function pageImages(User $owner): self
    {
        return static::systemAlbum(self::PAGE_IMAGES, __('Page images'), __('Photos uploaded while writing pages. They are shown on those pages even though this album is private.'), $owner);
    }

    /** $owner's photos uploaded before they belong anywhere (e.g. while creating an album). */
    public static function inbox(User $owner): self
    {
        return static::systemAlbum(self::INBOX, __('Unsorted uploads'), __('Photos uploaded before they were added to an album. Add them to an album, then they leave this one.'), $owner);
    }

    /** Someone's "Unsorted uploads" or "Page images". */
    public function isSystem(): bool
    {
        return $this->system_album !== null;
    }

    private static function systemAlbum(string $kind, string $title, string $description, User $owner): self
    {
        $existing = fn (): ?self => static::withTrashed()->where('owner_id', $owner->id)->where('system_album', $kind)->first();
        $album = $existing();

        if (! $album) {
            $album = new static(['title' => $title, 'owner_id' => $owner->id, 'visibility' => Visibility::Private, 'description' => $description]);

            try {
                $album->forceFill(['system_album' => $kind])->save();
            } catch (UniqueConstraintViolationException $e) {
                // Photos upload in parallel: another request created it a moment ago.
                $album = $existing() ?? throw $e;
            }
        }

        if ($album->trashed()) {
            $album->restore();
        }

        return $album;
    }

    /** Adds photos at the end of this album (no activity entry; uploads are logged per batch). */
    public function addPhotos(Photo|array $photos): void
    {
        $ids = $photos instanceof Photo ? [$photos->id] : array_map(fn ($p) => $p instanceof Photo ? $p->id : (int) $p, $photos);
        $ids = array_values(array_diff($ids, $this->photos()->pluck('photos.id')->all()));
        $next = (int) $this->photos()->max('album_photo.sort_order');

        // Photos leave "Unsorted uploads" through the pivot (AlbumPhoto::created).
        foreach ($ids as $i => $id) {
            $this->photos()->attach($id, ['sort_order' => $next + $i + 1]);
        }
    }

    /**
     * Takes photos out of this album, except photos that are in no other
     * album: every photo stays in at least one album (move it to the trash
     * to remove it). Logged once for the album ("removed 3 photos from Camp").
     * Photos that are not in this album are ignored.
     *
     * With $moveLastCopies (collaborators, who may not trash photos) a photo in no other
     * album moves to the "Unsorted uploads" of its uploader, or of the album's owner if
     * the uploader is gone, so it can leave this album and still stays in one.
     *
     * @param  Collection<int, Photo>  $photos
     * @return array{removed: int, kept: int} how many were removed, and how many kept because this is their only album
     */
    public function removePhotos(Collection $photos, bool $moveLastCopies = false): array
    {
        [$removable, $kept] = $photos->load('albums')
            ->filter(fn (Photo $photo) => $photo->albums->contains($this))
            ->partition(fn (Photo $photo) => $photo->albums->count() > 1);

        if ($moveLastCopies && $kept->isNotEmpty()) {
            $kept->load('uploader');
            [$movable, $kept] = $kept->partition(fn (Photo $photo) => ($photo->uploader ?? $this->owner) !== null);

            foreach ($movable->groupBy(fn (Photo $photo) => ($photo->uploader ?? $this->owner)->id) as $photosOfOnePerson) {
                self::inbox($photosOfOnePerson->first()->uploader ?? $this->owner)->addPhotos($photosOfOnePerson->modelKeys());
            }

            $removable = $removable->concat($movable);
        }

        // Covers are passed on through the pivot (AlbumPhoto::deleted).
        $this->photos()->detach($removable->modelKeys());
        ActivityLog::photosRemoved($this, $removable->count());

        return ['removed' => $removable->count(), 'kept' => $kept->count()];
    }

    /** If this album's cover is $photoId, switch to its next photo (or none). */
    public function releaseCover(int $photoId): void
    {
        if ($this->cover_photo_id === $photoId) {
            $this->updateQuietly(['cover_photo_id' => $this->photos()->whereKeyNot($photoId)->value('photos.id')]);
        }
    }

    /** "Summer camp 2026 › Day 3" */
    public function path(): string
    {
        $titles = [];
        $album = $this;

        while ($album && count($titles) < 6) {
            array_unshift($titles, $album->title);
            $album = $album->parent;
        }

        return implode(' › ', $titles);
    }

    /** @return BelongsTo<Photo, $this> */
    public function coverPhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'cover_photo_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function sharedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(AlbumUser::class);
    }

    /** @return BelongsToMany<Group, $this> */
    public function sharedGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->using(AlbumGroup::class);
    }

    /** @return BelongsToMany<User, $this> people invited to work on this album */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'album_collaborators')
            ->using(AlbumCollaborator::class)
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Group, $this> groups invited to work on this album */
    public function collaboratorGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'album_group_collaborators')
            ->using(AlbumGroupCollaborator::class)
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    /**
     * Whether $user (null = guest) may see this album. Unlisted albums are
     * additionally viewable with a valid share token (see AlbumController).
     *
     * Note: visibility is evaluated per album and is not inherited; a public
     * sub-album of a private parent is reachable on its own.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($this->hasDirectAccess($user)) {
            return true;
        }

        // After the end date only direct access remains. (A password, if set,
        // is checked on top of this per session: see App\Support\AlbumAccess.)
        if ($this->accessExpired()) {
            return false;
        }

        return match ($this->visibility) {
            Visibility::Public => $user !== null || config('gallery.public_albums_enabled'),
            Visibility::Registered => $user !== null,
            Visibility::Restricted, Visibility::Unlisted, Visibility::Private => false,
        };
    }

    public function isSharedWith(User $user): bool
    {
        return in_array($this->id, self::idsSharedWith($user), true);
    }

    /** RequestMemo key prefix of idsSharedWith(); forgotten whenever a share or group membership changes (AppServiceProvider). */
    public const SHARES_MEMO = 'album-shares.';

    /**
     * Ids of the albums shared with $user directly or through one of their
     * groups. Worked out once per request (RequestMemo, never cached across
     * requests), so checking many albums or photos costs two queries.
     *
     * @return list<int>
     */
    public static function idsSharedWith(User $user): array
    {
        return RequestMemo::instance()->remember(self::SHARES_MEMO.$user->id, fn () => DB::table('album_user')
            ->where('user_id', $user->id)
            ->pluck('album_id')
            ->merge(DB::table('album_group')
                ->join('group_user', 'group_user.group_id', '=', 'album_group.group_id')
                ->where('group_user.user_id', $user->id)
                ->pluck('album_group.album_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * Albums that should appear in listings for $user. Mirrors isVisibleTo(),
     * except that unlisted albums are only listed for their owner.
     *
     * @param  Builder<Album>  $query
     */
    /**
     * Albums $user works with in the admin: every album for people who may
     * see, edit or delete any album, otherwise only their own.
     *
     * @param  Builder<Album>  $query
     */
    public function scopeManageableBy(Builder $query, User $user): void
    {
        if (! self::managesEveryAlbum($user)) {
            $query->where($query->qualifyColumn('owner_id'), $user->id);
        }
    }

    /**
     * Albums $user opens in the admin: the ones they manage plus the ones they
     * work on through a collaborator role. What they may do there is up to
     * AlbumPolicy; a role never counts as managing the album.
     *
     * @param  Builder<Album>  $query
     */
    public function scopeWorkableBy(Builder $query, User $user): void
    {
        if (self::managesEveryAlbum($user)) {
            return;
        }

        $roleAlbums = AlbumCollaboration::albumIdsFor($user);

        // Sub-albums they created stay reachable in the trash, where a role no longer applies.
        $query->where(fn (Builder $q) => $q
            ->where($q->qualifyColumn('owner_id'), $user->id)
            ->orWhereIntegerInRaw($q->qualifyColumn('id'), $roleAlbums)
            ->orWhere(fn (Builder $created) => $created
                ->where($created->qualifyColumn('created_by'), $user->id)
                ->whereIntegerInRaw($created->qualifyColumn('parent_id'), $roleAlbums)));
    }

    public static function managesEveryAlbum(User $user): bool
    {
        return $user->canAny([Permission::ViewAnyAlbum->value, Permission::EditAnyAlbum->value, Permission::DeleteAnyAlbum->value]);
    }

    /**
     * Albums that may be listed for $user. Without $withCollaborations it
     * leaves out albums they reach only through a collaborator role:
     * Photo::scopeVisibleTo() uses that, so a role never makes someone else's
     * photos reusable in the admin (own albums, pages, covers).
     *
     * @param  Builder<Album>  $query
     */
    public function scopeListableFor(Builder $query, ?User $user, bool $withCollaborations = true): void
    {
        if ($user?->can(Permission::ViewAnyAlbum->value)) {
            return;
        }

        if (! $user && ! config('gallery.public_albums_enabled')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $notExpired = fn (Builder $q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now());

        $collaborations = $user && $withCollaborations ? AlbumCollaboration::albumIdsFor($user) : [];

        $query->where(function (Builder $q) use ($user, $notExpired, $collaborations) {
            $q->where(fn (Builder $p) => $p->where('visibility', Visibility::Public)->where($notExpired));

            if ($collaborations !== []) {
                $q->orWhereIntegerInRaw($q->qualifyColumn('id'), $collaborations);
            }

            if ($user) {
                $q->orWhere('owner_id', $user->id)
                    ->orWhere(fn (Builder $r) => $r->where('visibility', Visibility::Registered)->where($notExpired))
                    ->orWhere(fn (Builder $r) => $r
                        ->where('visibility', Visibility::Restricted)
                        ->where(fn (Builder $s) => $s
                            ->whereHas('sharedUsers', fn (Builder $u) => $u->whereKey($user->id))
                            ->orWhereHas('sharedGroups.users', fn (Builder $u) => $u->whereKey($user->id))));
            }
        });
    }
}
