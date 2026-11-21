<?php

namespace App\Models;

use App\Models\Pivots\AlbumGroup;
use App\Models\Pivots\AlbumGroupCollaborator;
use App\Models\Pivots\GroupUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A set of users (e.g. "Family", "Scout leaders") that albums can be shared
 * with. Groups are about *audience*; roles are about *capabilities*.
 */
#[Fillable(['name', 'slug', 'description'])]
class Group extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Group $group) {
            $group->slug ??= Str::slug($group->name);
        });
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(GroupUser::class);
    }

    /** @return BelongsToMany<Album, $this> */
    public function albums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class)->using(AlbumGroup::class);
    }

    /** @return BelongsToMany<Album, $this> albums this group was invited to work on */
    public function collaborationAlbums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class, 'album_group_collaborators')
            ->using(AlbumGroupCollaborator::class)
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }
}
