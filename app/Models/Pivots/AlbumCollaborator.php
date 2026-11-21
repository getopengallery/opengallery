<?php

namespace App\Models\Pivots;

use App\Enums\CollaboratorRole;
use App\Models\Album;
use App\Support\ActivityLog;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A person's role on an album. System albums never have collaborators, and
 * the owner is never one: their rights already go further than any role.
 */
class AlbumCollaborator extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'album_collaborators';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => CollaboratorRole::class];
    }

    protected static function booted(): void
    {
        static::creating(function (AlbumCollaborator $link): bool {
            $album = Album::withTrashed()->find($link->album_id);

            return $album !== null && ! $album->isSystem() && $album->owner_id !== (int) $link->user_id;
        });

        static::updated(fn (AlbumCollaborator $link) => ActivityLog::collaboratorRoleChanged($link));
    }
}
