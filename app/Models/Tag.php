<?php

namespace App\Models;

use App\Models\Pivots\AlbumTag;
use App\Models\Pivots\PhotoTag;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * One label shared by everyone. Names that differ only in case or extra
 * spaces are the same tag (normalize()); anything else is a tag of its own,
 * and the tag field shows similar ones (searchKey()) so people can pick one
 * instead. The rules for choosing and suggesting tags are in App\Support\Tagging.
 */
#[Fillable(['name', 'normalized', 'created_by'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public const MAX_LENGTH = 50;

    protected static function booted(): void
    {
        static::creating(function (Tag $tag) {
            $tag->normalized ??= self::normalize($tag->name);
            $tag->search_key ??= self::searchKey($tag->name);
        });
    }

    /** The name as it is shown: no control characters, single spaces. */
    public static function clean(string $name): string
    {
        return Str::squish(preg_replace('/\p{C}+/u', ' ', $name) ?? '');
    }

    /** What makes two names the same tag: "PET 1" and "pet  1" both give "pet 1". */
    public static function normalize(string $name): string
    {
        return mb_strtolower(self::clean($name));
    }

    /** Only letters and numbers, to find similar tags: "PET 1", "Pet-1" and "pet1" all give "pet1". */
    public static function searchKey(string $name): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', $name) ?? '');
    }

    /**
     * Includes albums in the trash, so a tag they use is kept for when they come back.
     *
     * @return BelongsToMany<Album, $this>
     */
    public function albums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class)->using(AlbumTag::class)->withTrashed();
    }

    /**
     * Includes photos in the trash, so a tag they use is kept for when they come back.
     *
     * @return BelongsToMany<Photo, $this>
     */
    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Photo::class)->using(PhotoTag::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
