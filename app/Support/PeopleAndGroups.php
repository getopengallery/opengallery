<?php

namespace App\Support;

use App\Models\Group;
use App\Models\User;

/**
 * One list of people and groups for pickers (who an album is shared with, who
 * works on it). An entry is a key such as "user:12" or "group:3". Keys come
 * from the browser, so split() only returns records that exist.
 */
class PeopleAndGroups
{
    public const USER = 'user';

    public const GROUP = 'group';

    /** Groups offered before anything is typed. People are only ever found by search. */
    private const GROUPS_OFFERED = 50;

    public static function key(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    /**
     * The people and groups behind $keys. Unknown types and missing records are dropped.
     *
     * @param  iterable<int, mixed>  $keys
     * @return array{users: list<int>, groups: list<int>}
     */
    public static function split(iterable $keys): array
    {
        $ids = [self::USER => [], self::GROUP => []];

        foreach ($keys as $key) {
            if (is_string($key) && preg_match('/^(user|group):([1-9]\d*)$/', $key, $match)) {
                $ids[$match[1]][] = (int) $match[2];
            }
        }

        return [
            'users' => $ids[self::USER] === [] ? [] : User::query()->whereKey($ids[self::USER])->pluck('id')->all(),
            'groups' => $ids[self::GROUP] === [] ? [] : Group::query()->whereKey($ids[self::GROUP])->pluck('id')->all(),
        ];
    }

    /**
     * People and groups matching $term, people first.
     *
     * @param  list<string>  $exceptKeys
     * @return array<string, string> labels by key
     */
    public static function search(string $term, array $exceptKeys = []): array
    {
        $except = self::idsOf($exceptKeys);

        return [
            ...self::userOptions(self::searchUsers($term, $except[self::USER])),
            ...self::groupOptions(self::searchGroups($term, $except[self::GROUP])),
        ];
    }

    /**
     * What a picker shows before anything is typed.
     *
     * @param  list<string>  $exceptKeys
     * @return array<string, string> labels by key
     */
    public static function offeredGroups(array $exceptKeys = []): array
    {
        return self::groupOptions(Group::query()
            ->whereKeyNot(self::idsOf($exceptKeys)[self::GROUP])
            ->orderBy('name')
            ->limit(self::GROUPS_OFFERED)
            ->pluck('name', 'id')
            ->all());
    }

    /**
     * Labels for chosen keys.
     *
     * @param  iterable<int, mixed>  $keys
     * @return array<string, string> labels by key
     */
    public static function labels(iterable $keys): array
    {
        $split = self::split($keys);

        return [
            ...self::userOptions(User::query()->whereKey($split['users'])->orderBy('name')->pluck('name', 'id')->all()),
            ...self::groupOptions(Group::query()->whereKey($split['groups'])->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    /**
     * People found by name or e-mail address. Wildcards typed by the person
     * are taken literally, so "%" cannot list the directory.
     *
     * @param  list<int>  $exceptIds
     * @return array<int, string> names by id
     */
    public static function searchUsers(string $term, array $exceptIds = [], int $limit = 20): array
    {
        $pattern = self::likePattern($term);

        if ($pattern === null) {
            return [];
        }

        return User::query()
            ->whereKeyNot($exceptIds)
            ->where(fn ($query) => $query
                ->whereRaw("name like ? escape '!'", [$pattern])
                ->orWhereRaw("email like ? escape '!'", [$pattern]))
            ->orderBy('name')
            ->limit($limit)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $exceptIds
     * @return array<int, string> names by id
     */
    public static function searchGroups(string $term, array $exceptIds = [], int $limit = 20): array
    {
        $pattern = self::likePattern($term);

        if ($pattern === null) {
            return [];
        }

        return Group::query()
            ->whereKeyNot($exceptIds)
            ->whereRaw("name like ? escape '!'", [$pattern])
            ->orderBy('name')
            ->limit($limit)
            ->pluck('name', 'id')
            ->all();
    }

    /** "%term%" with wildcards escaped for `like ? escape '!'`; null for fewer than two characters. */
    public static function likePattern(string $term): ?string
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return null;
        }

        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    private static function userOptions(array $names): array
    {
        $options = [];

        foreach ($names as $id => $name) {
            $options[self::key(self::USER, $id)] = $name;
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    private static function groupOptions(array $names): array
    {
        $options = [];

        foreach ($names as $id => $name) {
            $options[self::key(self::GROUP, $id)] = __(':name (group)', ['name' => $name]);
        }

        return $options;
    }

    /**
     * @param  list<string>  $keys
     * @return array{user: list<int>, group: list<int>}
     */
    private static function idsOf(array $keys): array
    {
        $ids = [self::USER => [], self::GROUP => []];

        foreach ($keys as $key) {
            [$type, $id] = array_pad(explode(':', $key, 2), 2, '');

            if (isset($ids[$type]) && ctype_digit($id)) {
                $ids[$type][] = (int) $id;
            }
        }

        return $ids;
    }
}
