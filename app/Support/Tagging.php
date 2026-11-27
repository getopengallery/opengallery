<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The rules for tags on albums and photos: which names are valid, which
 * tags someone is offered, and saving a record's tags. Whether tagging is on
 * and who may create tags comes from Admin → Settings → Tags (SiteSettings).
 *
 * Photos get the tags of their albums when read, and albums the tags of
 * albums above them that pass theirs on (`tags_apply_to_sub_albums`). These
 * are never copied, so removing a tag or moving a photo or album takes effect
 * at once.
 */
class Tagging
{
    public const MAX_PER_ITEM = 30;

    public const MAX_PREDEFINED = 200;

    private const MAX_SUGGESTIONS = 500;

    /** Upper bound of tags one search looks through, so a one-letter search stays cheap. */
    private const MAX_SEARCH_TAGS = 200;

    /** @return list<string> the tags set on this album or photo itself */
    public static function namesOf(Album|Photo $record): array
    {
        return $record->tags()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Tags offered while editing: always the predefined ones. While new tags
     * are allowed also every tag in use (global suggestions) or only the ones
     * $user can reach: their own and those on albums and photos they may open.
     *
     * @return list<string>
     */
    public static function suggestionsFor(User $user): array
    {
        $predefined = SiteSettings::predefinedTags();

        if (! SiteSettings::allowsCustomTags()) {
            return $predefined;
        }

        $inUse = fn (Builder $query) => $query->has('albums')->orHas('photos');

        $names = Tag::query()
            ->when(
                SiteSettings::globalTagSuggestions(),
                fn (Builder $query) => $query->where($inUse),
                fn (Builder $query) => self::reachableBy($query, $user, $inUse),
            )
            ->orderBy('name')
            ->limit(self::MAX_SUGGESTIONS)
            ->pluck('name')
            ->all();

        $suggestions = self::uniqueNames([...$predefined, ...$names]);
        sort($suggestions, SORT_NATURAL | SORT_FLAG_CASE);

        return $suggestions;
    }

    /**
     * Valid names, cleaned, one per tag (the first spelling wins).
     *
     * @param  array<int, mixed>  $names
     * @return list<string>
     */
    public static function uniqueNames(array $names): array
    {
        $unique = [];

        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }

            $clean = Tag::clean($name);
            $key = Tag::normalize($clean);

            if (Tag::searchKey($clean) !== '' && mb_strlen($clean) <= Tag::MAX_LENGTH) {
                $unique[$key] ??= $clean;
            }
        }

        return array_values($unique);
    }

    /**
     * What is wrong with these names for $record, or null. While only
     * predefined tags are allowed, tags it already has may stay.
     *
     * @param  array<int, mixed>  $names
     */
    public static function problemWith(array $names, Album|Photo|null $record): ?string
    {
        if (count($names) > self::MAX_PER_ITEM) {
            return __('Use at most :count tags.', ['count' => self::MAX_PER_ITEM]);
        }

        $allowed = SiteSettings::allowsCustomTags() ? null : [
            ...array_map(Tag::normalize(...), SiteSettings::predefinedTags()),
            ...($record?->tags()->pluck('normalized')->all() ?? []),
        ];

        foreach ($names as $name) {
            $problem = self::nameProblem($name);

            if ($problem !== null) {
                return $problem;
            }

            if ($allowed !== null && ! in_array(Tag::normalize($name), $allowed, true)) {
                return __('":tag" is not one of the predefined tags.', ['tag' => Tag::clean($name)]);
            }
        }

        return null;
    }

    /** What is wrong with one name, or null. */
    public static function nameProblem(mixed $name): ?string
    {
        $clean = is_string($name) ? Tag::clean($name) : '';

        if (Tag::searchKey($clean) === '') {
            return __('":tag" is not a valid tag. Tags need at least one letter or number.', ['tag' => is_string($name) ? $name : '']);
        }

        return mb_strlen($clean) > Tag::MAX_LENGTH
            ? __('Tags can have at most :count characters.', ['count' => Tag::MAX_LENGTH])
            : null;
    }

    /**
     * Makes these the record's own tags, creating new ones where allowed.
     * Names that may not be used are left out; the form reports them first.
     *
     * @param  array<int, mixed>  $names
     */
    public static function sync(Album|Photo $record, array $names, User $user): void
    {
        $current = $record->tags()->pluck('normalized')->all();
        $allowsCustom = SiteSettings::allowsCustomTags();
        $predefined = [];

        foreach (SiteSettings::predefinedTags() as $name) {
            $predefined[Tag::normalize($name)] = $name;
        }

        $ids = [];

        foreach (array_slice(self::uniqueNames($names), 0, self::MAX_PER_ITEM) as $name) {
            $key = Tag::normalize($name);

            if ($allowsCustom || isset($predefined[$key]) || in_array($key, $current, true)) {
                $ids[] = Tag::firstOrCreate(['normalized' => $key], ['name' => $predefined[$key] ?? $name, 'created_by' => $user->id])->id;
            }
        }

        $changes = $record->tags()->sync($ids);

        self::deleteUnused($changes['detached']);
    }

    /**
     * Adds these tags to the record's own tags (bulk tagging), keeping the
     * ones it has. Names that may not be used are left out, and so are tags
     * past the limit per item.
     *
     * @param  array<int, mixed>  $names
     * @return bool whether every tag fit within the limit
     */
    public static function add(Album|Photo $record, array $names, User $user): bool
    {
        $current = $record->tags()->pluck('normalized')->all();
        $allowsCustom = SiteSettings::allowsCustomTags();
        $predefined = [];

        foreach (SiteSettings::predefinedTags() as $name) {
            $predefined[Tag::normalize($name)] = $name;
        }

        $ids = [];
        $fits = true;

        foreach (self::uniqueNames($names) as $name) {
            $key = Tag::normalize($name);

            if (in_array($key, $current, true) || ! ($allowsCustom || isset($predefined[$key]))) {
                continue;
            }

            if (count($current) >= self::MAX_PER_ITEM) {
                $fits = false;

                continue;
            }

            $current[] = $key;
            $ids[] = Tag::firstOrCreate(['normalized' => $key], ['name' => $predefined[$key] ?? $name, 'created_by' => $user->id])->id;
        }

        $record->tags()->syncWithoutDetaching($ids);

        return $fits;
    }

    /**
     * Takes these tags off the record's own tags (bulk tagging); tags it
     * gets from its albums stay.
     *
     * @param  array<int, mixed>  $names
     * @return bool whether the record had any of them
     */
    public static function remove(Album|Photo $record, array $names): bool
    {
        $keys = array_map(Tag::normalize(...), self::uniqueNames($names));
        $ids = $record->tags()->whereIn('normalized', $keys)->pluck('tags.id')->all();

        if ($ids === []) {
            return false;
        }

        $record->tags()->detach($ids);
        self::deleteUnused($ids);

        return true;
    }

    /**
     * A tag nothing uses any more is gone, so a typo is not suggested to the next person.
     *
     * @param  array<int, int>  $ids
     */
    private static function deleteUnused(array $ids): void
    {
        Tag::query()->whereKey($ids)->doesntHave('albums')->doesntHave('photos')->delete();
    }

    /**
     * Tags $photo gets from its albums and from albums above them that pass
     * their tags on, limited to albums $user may open: a tag on someone's
     * private album says nothing to people who cannot see it.
     *
     * @return list<string>
     */
    public static function inheritedNames(Photo $photo, User $user): array
    {
        $albumIds = DB::table('album_photo')->where('photo_id', $photo->id)->pluck('album_id')->map(fn ($id) => (int) $id)->all();

        return self::namesFrom($albumIds, self::idsAbove($albumIds), $user);
    }

    /**
     * Tags $album gets from albums above it that pass their tags on, limited
     * to albums $user may open.
     *
     * @return list<string>
     */
    public static function namesFromParents(Album $album, User $user): array
    {
        return self::namesFrom([], self::idsAbove([$album->id]), $user);
    }

    /**
     * Ids of all albums below these, at every level (not in the trash).
     *
     * @param  list<int>  $albumIds
     * @return list<int>
     */
    public static function idsBelow(array $albumIds): array
    {
        $below = [];
        $pending = $albumIds;

        while ($pending !== []) {
            $children = Album::query()->whereIntegerInRaw('parent_id', $pending)->pluck('id')->all();
            $pending = array_values(array_diff($children, $below));
            $below = [...$below, ...$pending];
        }

        return $below;
    }

    /**
     * @param  list<int>  $albumIds
     * @return list<int> ids of all albums above these
     */
    private static function idsAbove(array $albumIds): array
    {
        $above = [];
        $pending = $albumIds;

        while ($pending !== []) {
            $parents = DB::table('albums')->whereIntegerInRaw('id', $pending)->whereNotNull('parent_id')
                ->pluck('parent_id')->map(fn ($id) => (int) $id)->unique()->all();
            $pending = array_values(array_diff($parents, $above));
            $above = [...$above, ...$pending];
        }

        return $above;
    }

    /**
     * Names of the tags on $albumIds and on those of $parentIds that pass
     * their tags on, counting only albums $user may open.
     *
     * @param  list<int>  $albumIds
     * @param  list<int>  $parentIds
     * @return list<string>
     */
    private static function namesFrom(array $albumIds, array $parentIds, User $user): array
    {
        $sources = self::openAlbums($user)->where(fn (Builder $albums) => $albums
            ->whereIntegerInRaw('albums.id', $albumIds)
            ->orWhere(fn (Builder $parents) => $parents
                ->whereIntegerInRaw('albums.id', $parentIds)
                ->where('albums.tags_apply_to_sub_albums', true)));

        return Tag::query()
            ->whereIn('tags.id', DB::table('album_tag')->select('tag_id')->whereIn('album_id', $sources))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Ids of the tags a search term finds, compared like tags are: searching
     * "pet 1" finds "PET-1". Nothing while tagging is off.
     *
     * @return list<int>
     */
    public static function idsMatching(string $term): array
    {
        $key = Tag::searchKey($term);

        if ($key === '' || ! SiteSettings::taggingEnabled()) {
            return [];
        }

        return Tag::query()->where('search_key', 'like', '%'.$key.'%')->limit(self::MAX_SEARCH_TAGS)->pluck('id')->all();
    }

    /** Existing tags take the spelling the administrator chose for them. */
    public static function applyPredefinedSpelling(): void
    {
        $spelling = [];

        foreach (SiteSettings::predefinedTags() as $name) {
            $spelling[Tag::normalize($name)] = $name;
        }

        Tag::query()->whereIn('normalized', array_map('strval', array_keys($spelling)))->get()
            ->each(fn (Tag $tag) => $tag->update(['name' => $spelling[$tag->normalized] ?? $tag->name]));
    }

    /**
     * Limits $query to tags $user created that are still in use, and tags on
     * albums and photos they may open.
     *
     * @param  Builder<Tag>  $query
     */
    private static function reachableBy(Builder $query, User $user, callable $inUse): void
    {
        $albums = self::openAlbums($user);

        $photosInAlbums = DB::table('album_photo')->select('photo_id')->whereIn('album_id', $albums);
        $ownPhotos = DB::table('photos')->select('id')->where('uploader_id', $user->id);

        $query->where(fn (Builder $tags) => $tags
            ->where(fn (Builder $own) => $own->where('created_by', $user->id)->where($inUse))
            ->orWhereIn('tags.id', DB::table('album_tag')->select('tag_id')->whereIn('album_id', $albums))
            ->orWhereIn('tags.id', DB::table('photo_tag')->select('tag_id')->whereIn('photo_id', $photosInAlbums))
            ->orWhereIn('tags.id', DB::table('photo_tag')->select('tag_id')->whereIn('photo_id', $ownPhotos)));
    }

    /**
     * Albums $user may open without entering anything: listed for them, and
     * without a password unless they have direct access.
     *
     * @return Builder<Album>
     */
    private static function openAlbums(User $user): Builder
    {
        $albums = Album::query()->listableFor($user)->select('albums.id');

        if ($user->can(Permission::ViewAnyAlbum->value)) {
            return $albums;
        }

        return $albums->where(fn (Builder $open) => $open
            ->whereNull('albums.password')
            ->orWhere('albums.owner_id', $user->id)
            ->orWhereIntegerInRaw('albums.id', AlbumCollaboration::albumIdsFor($user))
            ->orWhere(fn (Builder $shared) => $shared
                ->where('albums.visibility', Visibility::Restricted)
                ->whereIntegerInRaw('albums.id', Album::idsSharedWith($user))));
    }
}
