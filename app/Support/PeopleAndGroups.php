<?php

namespace App\Support;

use App\Models\Group;
use App\Models\User;

/**
 * One list of people and groups for pickers (who an album is shared with, who
 * works on it). An entry is a key such as "user:12" or "group:3". Keys come
 * from the browser, so split() only returns records that exist. Pickers that
 * allow it also take "email:someone@example.com" for people without an
 * account (AlbumGuests); split() returns those addresses separately.
 */
class PeopleAndGroups
{
    public const USER = 'user';

    public const GROUP = 'group';

    public const EMAIL = 'email';

    /** Groups offered before anything is typed. People are only ever found by search. */
    private const GROUPS_OFFERED = 50;

    public static function key(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    /**
     * The people and groups behind $keys. Unknown types and missing records
     * are dropped; e-mail keys come back as valid, lower-case addresses.
     *
     * @param  iterable<int, mixed>  $keys
     * @return array{users: list<int>, groups: list<int>, emails: list<string>}
     */
    public static function split(iterable $keys): array
    {
        $ids = [self::USER => [], self::GROUP => []];
        $emails = [];

        foreach ($keys as $key) {
            if (is_string($key) && preg_match('/^(user|group):([1-9]\d*)$/', $key, $match)) {
                $ids[$match[1]][] = (int) $match[2];
            } elseif (is_string($key) && str_starts_with($key, self::EMAIL.':') && ($email = AlbumGuests::normalize(substr($key, strlen(self::EMAIL) + 1)))) {
                $emails[] = $email;
            }
        }

        return [
            'users' => $ids[self::USER] === [] ? [] : User::query()->whereKey($ids[self::USER])->pluck('id')->all(),
            'groups' => $ids[self::GROUP] === [] ? [] : Group::query()->whereKey($ids[self::GROUP])->pluck('id')->all(),
            'emails' => array_values(array_unique($emails)),
        ];
    }

    public static function emailKey(string $email): string
    {
        return self::EMAIL.':'.$email;
    }

    /**
     * The "invite by e-mail" entry for $term, when it is an e-mail address
     * that belongs to no account (an account is found by search instead).
     *
     * @return array<string, string>
     */
    public static function emailOption(string $term): array
    {
        $email = AlbumGuests::normalize($term);

        if ($email === null || User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return [];
        }

        return [self::emailKey($email) => __('✉ :email (invite by e-mail)', ['email' => $email])];
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

        $emails = [];

        foreach ($split['emails'] as $email) {
            $emails[self::emailKey($email)] = __('✉ :email', ['email' => $email]);
        }

        return [
            ...self::userOptions(User::query()->whereKey($split['users'])->orderBy('name')->pluck('name', 'id')->all()),
            ...self::groupOptions(Group::query()->whereKey($split['groups'])->orderBy('name')->pluck('name', 'id')->all()),
            ...$emails,
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
