<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Pivots\AlbumPhoto;
use App\Models\Pivots\PhotoTag;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Metadata row for one image. The binary itself (plus thumbnails) is managed
 * by spatie/laravel-medialibrary in the "original" collection.
 */
#[Fillable(['uploader_id', 'title', 'description', 'taken_at', 'width', 'height', 'exif'])]
class Photo extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    public const COLLECTION = 'original';

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'exif' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION)
            ->singleFile()
            ->useDisk(config('gallery.media_disk'))
            ->acceptsMimeTypes(config('gallery.accepted_mime_types'));
    }

    /** EXIF orientations of photos stored a quarter turn off: shown upright, so width and height swap. */
    public const TURNED_ORIENTATIONS = [6, 8];

    /** WebP quality of the conversions (GD's own default; set so Imagick gives the same). */
    public const CONVERSION_QUALITY = 80;

    /** Longest side of each conversion, in pixels: lightbox, grids on large screens, grids on phones. */
    public const CONVERSION_SIZES = ['preview' => 1920, 'thumb' => 720, 'small' => 360];

    /**
     * Conversions are not run through the media library's image optimizers:
     * cwebp & co. re-encode the finished WebP at a higher quality, which made
     * files larger (up to twice the size) and uploads seconds slower.
     *
     * `preview` comes first: thumb and small are made from it
     * (App\Support\ConversionsFromPreview), so the original is decoded once.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (self::CONVERSION_SIZES as $name => $size) {
            // The image library turns photos upright by their EXIF orientation when it loads them.
            $conversion = $this->addMediaConversion($name)
                ->fit(Fit::Max, $size, $size)
                ->format('webp')
                ->quality(self::CONVERSION_QUALITY)
                ->nonOptimized();

            if (! config('gallery.queue_conversions')) {
                $conversion->nonQueued();
            }
        }
    }

    /**
     * Albums this photo is in (a photo can be in several). Includes albums in
     * the trash, so the admin can still show them.
     *
     * @return BelongsToMany<Album, $this>
     */
    public function albums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class)
            ->using(AlbumPhoto::class)
            ->withPivot('sort_order')
            ->withTrashed();
    }

    /**
     * Tags set on the photo itself; those from its albums are worked out when
     * needed (App\Support\Tagging).
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->using(PhotoTag::class);
    }

    /** @return Collection<int, Album> albums that are not in the trash */
    public function activeAlbums(): Collection
    {
        return $this->albums->reject(fn (Album $album) => $album->trashed())->values();
    }

    /** @return HasMany<Album, $this> albums that use this photo as their cover */
    public function coverOf(): HasMany
    {
        return $this->hasMany(Album::class, 'cover_photo_id');
    }

    /**
     * Photos $user may see in the media library: everything for staff who may
     * view any album, otherwise photos in albums they can see plus their own uploads.
     *
     * @param  Builder<Photo>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can(Permission::ViewAnyAlbum->value)) {
            return;
        }

        // The albums they may list are worked out once (like albums(), trashed ones count), then their
        // photos are looked up through album_photo: no correlated query for every photo in the library.
        // Collaborator roles do not count: they give access on the site, not photos to reuse in the admin.
        $listableAlbums = Album::withTrashed()->listableFor($user, false)->select('albums.id');

        $query->where(fn (Builder $q) => $q
            ->where($q->qualifyColumn('uploader_id'), $user->id)
            ->orWhereIn($q->qualifyColumn('id'), DB::table('album_photo')->select('photo_id')->whereIn('album_id', $listableAlbums)));
    }

    /**
     * Photos $user may add to an album or make its cover: the ones they may
     * already edit (PhotoPolicy::update() in SQL). Being able to see a photo is
     * not enough, or anyone with a view of a restricted album could copy its
     * photos into their own public album and gain the original file and edit
     * rights through it. Pages are different: a photo placed on one is only
     * shown there (Page::unusablePhotoIds()).
     *
     * @param  Builder<Photo>  $query
     */
    public function scopeReusableBy(Builder $query, User $user): void
    {
        if ($user->can(Permission::EditAnyAlbum->value)) {
            return;
        }

        $ownAlbums = Album::withTrashed()->where('owner_id', $user->id)->select('albums.id');

        $query->where(fn (Builder $q) => $q
            ->where($q->qualifyColumn('uploader_id'), $user->id)
            ->orWhereIn($q->qualifyColumn('id'), DB::table('album_photo')->select('photo_id')->whereIn('album_id', $ownAlbums)));
    }

    /**
     * Photos $user may move to the trash, restore or delete permanently
     * (PhotoPolicy::delete() in SQL): their own uploads, and photos all of
     * whose albums (trashed ones included) they may edit. Trashed photos are
     * only listed for these people.
     *
     * @param  Builder<Photo>  $query
     */
    public function scopeTrashableBy(Builder $query, User $user): void
    {
        $editsEveryAlbum = $user->can(Permission::EditAnyAlbum->value);

        $query->where(fn (Builder $q) => $q
            ->where($q->qualifyColumn('uploader_id'), $user->id)
            ->orWhere(fn (Builder $managed) => $managed
                ->whereHas('albums')
                ->unless($editsEveryAlbum, fn (Builder $mine) => $mine->whereDoesntHave('albums', fn (Builder $album) => $album
                    ->where(fn (Builder $other) => $other->whereNull('albums.owner_id')->orWhere('albums.owner_id', '!=', $user->id))))));
    }

    /**
     * Width divided by height, as the photo is shown (upright). 1.5 while
     * the size is unknown. Grids use it to keep each photo's shape.
     */
    public function aspectRatio(): float
    {
        return $this->width && $this->height ? round($this->width / $this->height, 4) : 1.5;
    }

    /** The original file (media library item), if present. */
    public function original(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION);
    }

    /**
     * Address of a conversion of the original ("thumb" or "preview"), served
     * by MediaController, which decides who gets the file. Null while the
     * conversion is not generated yet.
     */
    public function mediaUrl(string $conversion): ?string
    {
        $media = $this->original();

        return $media?->hasGeneratedConversion($conversion) ? route('media.show', [$media, $conversion]) : null;
    }

    /** @return list<string> e.g. ["Summer camp 2026 › Day 3", "Best of 2026"] */
    public function albumPaths(): array
    {
        return $this->albums->map(fn (Album $album) => $album->path())->values()->all();
    }

    protected static function booted(): void
    {
        // Keep album covers pointing at a photo that is actually visible.
        static::deleted(fn (Photo $photo) => $photo->albums->each(fn (Album $album) => $album->releaseCover($photo->id)));
        static::restored(fn (Photo $photo) => $photo->albums
            ->filter(fn (Album $album) => $album->cover_photo_id === null)
            ->each(fn (Album $album) => $album->updateQuietly(['cover_photo_id' => $photo->id])));
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
