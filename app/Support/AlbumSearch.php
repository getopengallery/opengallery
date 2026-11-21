<?php

namespace App\Support;

use App\Models\Album;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Search on the site's album list (title and description, at any level).
 *
 * Only finds albums a visitor could also reach by browsing: the album and
 * every album above it must be listable for them (Album::scopeListableFor()).
 * An album inside a hidden album therefore stays out of the results, as it
 * stays out of the lists (see docs/user/content/sharing.md#visibility).
 */
class AlbumSearch
{
    /** Upper bound of matches looked at, so a one-letter search stays cheap. */
    private const MAX_MATCHES = 500;

    public const PER_PAGE = 24;

    /** @return LengthAwarePaginator<int, Album> */
    public static function run(?User $user, string $term): LengthAwarePaginator
    {
        $like = '%'.addcslashes($term, '\\%_').'%';

        $matches = Album::query()
            ->listableFor($user)
            ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like))
            ->with(['coverPhoto.media', 'parent'])
            ->withCount('photos')
            ->orderBy('tree_path')
            ->limit(self::MAX_MATCHES)
            ->get();

        $found = self::reachable($matches, $user)->values();
        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $found->forPage($page, self::PER_PAGE)->values(),
            $found->count(),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => ['q' => $term]],
        );
    }

    /**
     * The albums of $albums whose ancestors are all listable for $user.
     *
     * @param  Collection<int, Album>  $albums
     * @return Collection<int, Album>
     */
    private static function reachable(Collection $albums, ?User $user): Collection
    {
        // Parent of every listable ancestor, found level by level.
        $listableParents = [];
        $pending = $albums->pluck('parent_id')->filter()->unique()->values()->all();

        while ($pending !== []) {
            $found = Album::query()->listableFor($user)->whereKey($pending)->pluck('parent_id', 'id')->all();
            $listableParents += $found;
            $pending = array_values(array_diff(array_filter($found), array_keys($listableParents)));
        }

        $isReachable = function (?int $parentId) use ($listableParents): bool {
            while ($parentId !== null) {
                if (! array_key_exists($parentId, $listableParents)) {
                    return false;
                }
                $parentId = $listableParents[$parentId];
            }

            return true;
        };

        return $albums->filter(fn (Album $album) => $isReachable($album->parent_id));
    }
}
