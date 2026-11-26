<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The browsers where a person is signed in, from the database sessions, and
 * signing them out. Deleting a session row signs that browser out on its next
 * request. Signing out every device also replaces the "remember me" token, so
 * remembered browsers cannot sign straight back in. Only available with the
 * `database` session driver; with any other driver the lists stay empty.
 */
class UserSessions
{
    public static function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /** @return Builder<UserSession> Sessions of $user that have not expired. */
    public static function query(User $user): Builder
    {
        return UserSession::query()->where('user_id', $user->getKey())->active();
    }

    /** @return Collection<int, UserSession> */
    public static function for(User $user): Collection
    {
        return self::available() ? self::query($user)->get() : new Collection;
    }

    /** Sign out one session of $user; false when it is not theirs (or gone already). */
    public static function signOut(User $user, string $sessionId, ?User $by = null): bool
    {
        if (! self::available()) {
            return false;
        }

        $deleted = UserSession::query()->where('user_id', $user->getKey())->whereKey($sessionId)->delete() > 0;

        if ($deleted) {
            self::recorded($user, 1, $by);
        }

        return $deleted;
    }

    /**
     * Sign out every session of $user except $exceptSessionId (the current
     * browser when people sign out their other devices) and replace their
     * "remember me" token. Returns how many sessions were signed out.
     */
    public static function signOutAll(User $user, ?string $exceptSessionId = null, ?User $by = null): int
    {
        $count = self::available()
            ? UserSession::query()
                ->where('user_id', $user->getKey())
                ->when($exceptSessionId !== null, fn (Builder $query) => $query->whereKeyNot($exceptSessionId))
                ->delete()
            : 0;

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        self::recorded($user, $count, $by);

        return $count;
    }

    /** Log the sign-out, and tell the person when someone else did it. */
    private static function recorded(User $user, int $count, ?User $by): void
    {
        ActivityLog::sessionsSignedOut($user, $count, $by);

        if ($by && ! $by->is($user)) {
            SecurityNotices::sessionsSignedOut($user, $by);
        }
    }
}
