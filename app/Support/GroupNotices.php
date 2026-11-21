<?php

namespace App\Support;

use App\Enums\Visibility;
use App\Models\User;
use App\Notifications\GroupAlbumsShared;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tells people who were added to a group about the albums that gave them:
 * one notice per person, only when they can see something new. Called by the
 * admin forms where someone chooses to tell them, so groups filled at sign-in
 * by an identity provider stay quiet.
 */
class GroupNotices
{
    /**
     * Albums each person has through ownership, a share or a collaborator
     * role. A few queries, however many people.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<int>> user id => album ids
     */
    public static function accessOf(array $userIds): array
    {
        $access = array_fill_keys($userIds, []);

        $queries = [
            self::albums()->whereIntegerInRaw('albums.owner_id', $userIds)
                ->select('albums.owner_id as user_id', 'albums.id as album_id'),
            self::albums()->where('albums.visibility', Visibility::Restricted->value)
                ->join('album_user', 'album_user.album_id', '=', 'albums.id')
                ->whereIntegerInRaw('album_user.user_id', $userIds)
                ->select('album_user.user_id', 'albums.id as album_id'),
            self::albums()->where('albums.visibility', Visibility::Restricted->value)
                ->join('album_group', 'album_group.album_id', '=', 'albums.id')
                ->join('group_user', 'group_user.group_id', '=', 'album_group.group_id')
                ->whereIntegerInRaw('group_user.user_id', $userIds)
                ->select('group_user.user_id', 'albums.id as album_id'),
            self::albums()
                ->join('album_collaborators', 'album_collaborators.album_id', '=', 'albums.id')
                ->whereIntegerInRaw('album_collaborators.user_id', $userIds)
                ->select('album_collaborators.user_id', 'albums.id as album_id'),
            self::albums()
                ->join('album_group_collaborators', 'album_group_collaborators.album_id', '=', 'albums.id')
                ->join('group_user', 'group_user.group_id', '=', 'album_group_collaborators.group_id')
                ->whereIntegerInRaw('group_user.user_id', $userIds)
                ->select('group_user.user_id', 'albums.id as album_id'),
        ];

        foreach ($queries as $query) {
            foreach ($query->get() as $row) {
                $access[(int) $row->user_id][] = (int) $row->album_id;
            }
        }

        return array_map(fn (array $albums): array => array_values(array_unique($albums)), $access);
    }

    /**
     * Tells each of $userIds who can reach more albums than in $before.
     *
     * @param  list<int>  $userIds
     * @param  array<int, list<int>>  $before  accessOf() before the change
     * @return array{told: int, sent: int, failed: int}
     */
    public static function notifyNew(array $userIds, array $before, User $addedBy): array
    {
        $gained = [];

        foreach (self::accessOf($userIds) as $userId => $albums) {
            $new = count(array_diff($albums, $before[$userId] ?? []));

            if ($new > 0 && $userId !== $addedBy->id) {
                $gained[$userId] = $new;
            }
        }

        return Mailing::notifyEach(
            array_keys($gained),
            fn (User $user) => new GroupAlbumsShared($addedBy->name, $gained[$user->id]),
        );
    }

    private static function albums(): Builder
    {
        return DB::table('albums')->whereNull('albums.deleted_at')->whereNull('albums.system_album');
    }
}
