<?php

namespace App\Support\Statistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * How many members sign in, from `users.last_login_at` (set on every login,
 * whatever the activity log records). Accounts that have not logged in
 * since it was introduced, and that the log had no login for, count as
 * "never logged in". Cached for CACHE_SECONDS.
 */
class MemberActivityStats
{
    public const CACHE_SECONDS = 600;

    /** Days without a login after which a member counts as inactive. */
    public const INACTIVE_DAYS = 180;

    /** @var list<int> the "active in the last … days" figures */
    public const ACTIVE_DAYS = [7, 30, 90];

    /**
     * @return array{members: int, active: array<int, int>, inactive: int, never: int}
     */
    public static function overview(): array
    {
        return Cache::remember('statistics.members', self::CACHE_SECONDS, function (): array {
            $query = User::query()->toBase()->selectRaw('COUNT(*) as members');

            foreach (self::ACTIVE_DAYS as $days) {
                $query->selectRaw("SUM(CASE WHEN last_login_at >= ? THEN 1 ELSE 0 END) as active_{$days}", [now()->subDays($days)]);
            }

            $row = $query
                ->selectRaw('SUM(CASE WHEN last_login_at < ? THEN 1 ELSE 0 END) as inactive', [now()->subDays(self::INACTIVE_DAYS)])
                ->selectRaw('SUM(CASE WHEN last_login_at IS NULL THEN 1 ELSE 0 END) as never')
                ->first();

            return [
                'members' => (int) $row->members,
                'active' => collect(self::ACTIVE_DAYS)->mapWithKeys(fn (int $days) => [$days => (int) $row->{"active_{$days}"}])->all(),
                'inactive' => (int) $row->inactive,
                'never' => (int) $row->never,
            ];
        });
    }

    /**
     * Members who have not logged in for the longest time (never first, oldest accounts first among them).
     *
     * @return Collection<int, User>
     */
    public static function leastActive(int $limit = 10): Collection
    {
        return User::query()
            ->where(fn ($query) => $query->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(self::INACTIVE_DAYS)))
            ->orderByRaw('CASE WHEN last_login_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_login_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'created_at', 'last_login_at']);
    }
}
