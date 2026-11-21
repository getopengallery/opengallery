<?php

namespace App\Support;

use App\Enums\CollaboratorRole;
use App\Models\Album;
use App\Models\User;
use App\Notifications\AlbumCollaboratorAdded;
use Closure;

/**
 * Tells people when they start working on an album or get a higher role,
 * directly or through a group: in the gallery, and by e-mail while e-mail
 * works. Nobody who already had that access hears about it, and neither does
 * the person making the change or the album's owner.
 */
class CollaboratorNotices
{
    /**
     * Runs $change and tells those of $candidateIds whose role went up.
     *
     * @param  list<int>  $candidateIds  people the change may affect
     * @param  Closure(): void  $change
     * @return array{told: int, sent: int, failed: int}
     */
    public static function around(Album $album, array $candidateIds, User $changedBy, Closure $change): array
    {
        $candidateIds = array_values(array_diff(array_unique($candidateIds), [$changedBy->id, (int) $album->owner_id]));
        $before = AlbumCollaboration::rolesOn($album, $candidateIds);

        $change();

        $after = AlbumCollaboration::rolesOn($album, $candidateIds);
        $raised = array_filter(
            $after,
            fn (CollaboratorRole $role, int $userId) => ! isset($before[$userId]) || ! $before[$userId]->atLeast($role),
            ARRAY_FILTER_USE_BOTH,
        );

        return Mailing::notifyEach(
            array_keys($raised),
            fn (User $user) => new AlbumCollaboratorAdded($album, $changedBy->name, $raised[$user->id]),
        );
    }
}
