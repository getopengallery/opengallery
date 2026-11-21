<?php

namespace App\Support;

use App\Enums\CollaboratorRole;
use App\Models\Album;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The people and groups invited to one album, as one list: reading it page by
 * page and changing it. Callers check AlbumPolicy::manageCollaborators first.
 */
class AlbumCollaborators
{
    /** Entries per page in the album's Collaborators card. */
    public const PER_PAGE = 8;

    /**
     * One page of everyone who works on this album, also through its parents,
     * by name. Items have `key`, `type`, `name`, `role` (their highest on the
     * album and its parents) and `ownRole` (on this album itself, null when
     * it comes from a parent; only that one can be changed here).
     */
    public static function page(Album $album, string $search = '', int $page = 1): LengthAwarePaginator
    {
        $chain = AlbumCollaboration::selfAndAncestorIds($album);

        $people = self::rows('album_collaborators', 'users', 'user_id', PeopleAndGroups::USER, $album, $chain);
        $groups = self::rows('album_group_collaborators', 'groups', 'group_id', PeopleAndGroups::GROUP, $album, $chain);

        $pattern = PeopleAndGroups::likePattern($search);

        $entries = DB::query()
            ->fromSub($people->unionAll($groups), 'collaborators')
            ->when($pattern !== null, fn ($query) => $query->whereRaw("name like ? escape '!'", [$pattern]))
            ->orderBy('name')
            ->orderBy('type')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, page: $page);

        $entries->through(fn (object $row): object => (object) [
            'key' => PeopleAndGroups::key($row->type, (int) $row->id),
            'type' => $row->type,
            'name' => $row->name,
            'role' => CollaboratorRole::fromRank((int) $row->top_rank),
            'ownRole' => CollaboratorRole::fromRank((int) $row->own_rank),
        ]);

        return $entries;
    }

    /**
     * @param  list<int>  $chain  the album and its parents
     */
    private static function rows(string $table, string $subjects, string $column, string $type, Album $album, array $chain): Builder
    {
        $rank = "CASE {$table}.role WHEN 'contributor' THEN 1 WHEN 'organizer' THEN 2 ELSE 3 END";

        return DB::table($table)
            ->join($subjects, "{$subjects}.id", '=', "{$table}.{$column}")
            ->whereIn("{$table}.album_id", $chain)
            ->groupBy("{$subjects}.id", "{$subjects}.name")
            ->selectRaw("'{$type}' as type, {$subjects}.id as id, {$subjects}.name as name, MAX({$rank}) as top_rank, MAX(CASE WHEN {$table}.album_id = ? THEN {$rank} ELSE 0 END) as own_rank", [$album->id]);
    }

    /**
     * Keys of everyone with a role on this album itself.
     *
     * @return list<string>
     */
    public static function keys(Album $album): array
    {
        return [
            ...DB::table('album_collaborators')->where('album_id', $album->id)->pluck('user_id')
                ->map(fn ($id) => PeopleAndGroups::key(PeopleAndGroups::USER, (int) $id))->all(),
            ...DB::table('album_group_collaborators')->where('album_id', $album->id)->pluck('group_id')
                ->map(fn ($id) => PeopleAndGroups::key(PeopleAndGroups::GROUP, (int) $id))->all(),
        ];
    }

    /**
     * Gives the people and groups in $keys this role, and tells whoever gains
     * access or a higher role. Anyone already on the list gets the new role.
     *
     * @param  iterable<int, mixed>  $keys
     * @return array{told: int, sent: int, failed: int}
     */
    public static function add(Album $album, iterable $keys, CollaboratorRole $role, User $addedBy): array
    {
        $split = PeopleAndGroups::split($keys);

        return CollaboratorNotices::around($album, self::peopleIn($split), $addedBy, function () use ($album, $split, $role, $addedBy): void {
            self::assign($album->collaborators(), $split['users'], $role, $addedBy);
            self::assign($album->collaboratorGroups(), $split['groups'], $role, $addedBy);
        });
    }

    /** @return array{told: int, sent: int, failed: int} */
    public static function changeRole(Album $album, string $key, CollaboratorRole $role, User $changedBy): array
    {
        $split = PeopleAndGroups::split([$key]);

        return CollaboratorNotices::around($album, self::peopleIn($split), $changedBy, function () use ($album, $split, $role): void {
            foreach ($split['users'] as $id) {
                $album->collaborators()->updateExistingPivot($id, ['role' => $role->value]);
            }

            foreach ($split['groups'] as $id) {
                $album->collaboratorGroups()->updateExistingPivot($id, ['role' => $role->value]);
            }
        });
    }

    public static function remove(Album $album, string $key): void
    {
        $split = PeopleAndGroups::split([$key]);

        if ($split['users'] !== []) {
            $album->collaborators()->detach($split['users']);
        }

        if ($split['groups'] !== []) {
            $album->collaboratorGroups()->detach($split['groups']);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Relations\BelongsToMany<*, *>  $relation
     * @param  list<int>  $ids
     */
    private static function assign($relation, array $ids, CollaboratorRole $role, User $addedBy): void
    {
        $existing = $relation->newPivotQuery()->whereIn($relation->getRelatedPivotKeyName(), $ids)
            ->pluck($relation->getRelatedPivotKeyName())->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($ids, $existing) as $id) {
            $relation->attach($id, ['role' => $role->value, 'added_by' => $addedBy->id]);
        }

        foreach ($existing as $id) {
            $relation->updateExistingPivot($id, ['role' => $role->value]);
        }
    }

    /**
     * @param  array{users: list<int>, groups: list<int>}  $split
     * @return list<int> user ids
     */
    private static function peopleIn(array $split): array
    {
        $members = $split['groups'] === [] ? [] : DB::table('group_user')->whereIn('group_id', $split['groups'])->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique([...$split['users'], ...$members]));
    }
}
