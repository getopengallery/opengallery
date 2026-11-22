<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search on the site's album list: albums by title, description and tags (at
 * any level), and photos by their tags.
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

    public const PHOTOS_PER_PAGE = 60;

    /** @return LengthAwarePaginator<int, Album> */
    public static function run(?User $user, string $term): LengthAwarePaginator
    {
        $like = '%'.addcslashes($term, '\\%_').'%';
        $tagIds = Tagging::idsMatching($term);
        $inheriting = self::albumsGettingTags($tagIds, $user);

        $matches = Album::query()
            ->listableFor($user)
            ->select('albums.*')
            ->selectRaw('(albums.title LIKE ? OR albums.description LIKE ?) AS found_by_text', [$like, $like])
            ->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->when($tagIds !== [], fn (Builder $tagged) => $tagged
                    ->orWhereHas('tags', fn (Builder $tags) => $tags->whereKey($tagIds))
                    ->orWhereIntegerInRaw('albums.id', $inheriting)))
            ->with(['coverPhoto.media', 'parent'])
            ->withCount('photos')
            ->orderBy('tree_path')
            ->limit(self::MAX_MATCHES)
            ->get();

        // A tag is part of the album's content: behind a password it must not be searchable.
        $matches = $matches->filter(fn (Album $album) => $album->found_by_text || self::canOpen($album, $user));

        $found = self::reachable($matches, $user)->values();
        $page = Paginator::resolveCurrentPage();

        return (new LengthAwarePaginator(
            $found->forPage($page, self::PER_PAGE)->values(),
            $found->count(),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => ['q' => $term]],
        ))->withQueryString();
    }

    /**
     * Photos with a matching tag, their own or from an album, in albums the
     * visitor can open. A tag from an album they cannot open never counts,
     * even if the same photo is also in an album they can.
     *
     * @return LengthAwarePaginator<int, Photo>|null null when no tag matches
     */
    public static function photos(?User $user, string $term): ?LengthAwarePaginator
    {
        $tagIds = Tagging::idsMatching($term);

        if ($tagIds === []) {
            return null;
        }

        $inheriting = self::albumsGettingTags($tagIds, $user);
        $ownTags = DB::table('photo_tag')->select('photo_id')->whereIntegerInRaw('tag_id', $tagIds);

        $candidates = Album::query()
            ->listableFor($user)
            ->whereNull('system_album')
            ->where(fn (Builder $q) => $q
                ->whereHas('tags', fn (Builder $tags) => $tags->whereKey($tagIds))
                ->orWhereIntegerInRaw('albums.id', $inheriting)
                ->orWhereIn('albums.id', DB::table('album_photo')->select('album_id')->whereIn('photo_id', $ownTags)))
            ->orderBy('tree_path')
            ->limit(self::MAX_MATCHES)
            ->get();

        $openIds = self::reachable($candidates, $user)
            ->filter(fn (Album $album) => self::canOpen($album, $user))
            ->modelKeys();

        if ($openIds === []) {
            return null;
        }

        $taggedAlbumIds = DB::table('album_tag')
            ->whereIntegerInRaw('album_id', $openIds)
            ->whereIntegerInRaw('tag_id', $tagIds)
            ->pluck('album_id')
            ->merge(array_intersect($openIds, $inheriting))
            ->unique()
            ->all();

        return Photo::query()
            ->with('media')
            ->where(fn (Builder $q) => $q
                ->whereIn('photos.id', DB::table('album_photo')->select('photo_id')->whereIntegerInRaw('album_id', $taggedAlbumIds))
                ->orWhere(fn (Builder $own) => $own
                    ->whereIn('photos.id', $ownTags)
                    ->whereIn('photos.id', DB::table('album_photo')->select('photo_id')->whereIntegerInRaw('album_id', $openIds))))
            ->orderByDesc('photos.id')
            ->paginate(self::PHOTOS_PER_PAGE, pageName: 'photos')
            ->withQueryString();
    }

    /**
     * Albums that get one of these tags from an album above them. Only an
     * album the visitor can open passes its tags on: a tag behind a password
     * must not become searchable through a public sub-album.
     *
     * @param  list<int>  $tagIds
     * @return list<int>
     */
    private static function albumsGettingTags(array $tagIds, ?User $user): array
    {
        if ($tagIds === []) {
            return [];
        }

        $sources = Album::query()
            ->listableFor($user)
            ->where('tags_apply_to_sub_albums', true)
            ->whereHas('tags', fn (Builder $tags) => $tags->whereKey($tagIds))
            ->limit(self::MAX_MATCHES)
            ->get()
            ->filter(fn (Album $album) => self::canOpen($album, $user));

        return Tagging::idsBelow($sources->modelKeys());
    }

    /** No password, or the visitor has direct access or entered it. */
    private static function canOpen(Album $album, ?User $user): bool
    {
        return ! $album->hasPassword() || AlbumAccess::canView($user, $album);
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
