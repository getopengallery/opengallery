<?php

namespace App\Models\Pivots;

use App\Enums\CollaboratorRole;
use App\Models\Album;
use App\Support\ActivityLog;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** A group's role on an album: every current member has it. System albums never have collaborators. */
class AlbumGroupCollaborator extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'album_group_collaborators';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => CollaboratorRole::class];
    }

    protected static function booted(): void
    {
        static::creating(function (AlbumGroupCollaborator $link): bool {
            $album = Album::withTrashed()->find($link->album_id);

            return $album !== null && ! $album->isSystem();
        });

        static::updated(fn (AlbumGroupCollaborator $link) => ActivityLog::collaboratorRoleChanged($link));
    }
}
