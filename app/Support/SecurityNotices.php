<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\EmailAddressChanged;
use App\Notifications\LoginBlocked;
use App\Notifications\PasswordChanged;
use App\Notifications\SignUpAttempted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Tells people about changes to their own account that someone else could
 * have made: a new password, a new e-mail address, logins blocked after wrong
 * passwords. In the gallery, and by e-mail while e-mail works. Called where
 * the change happens (profile, reset link, the user form, the Lockout event),
 * not from model events: Laravel also rewrites password hashes on its own
 * when logging in, and an accepted invitation is not a change.
 */
class SecurityNotices
{
    /** Login-blocked notices go out at most once in this many seconds per account. */
    public const LOCKOUT_QUIET_SECONDS = 3600;

    public static function passwordChanged(User $user, ?User $changedBy = null): void
    {
        Mailing::send(fn () => $user->notify(new PasswordChanged(self::otherPerson($user, $changedBy))));
    }

    /**
     * In the gallery to the account, by e-mail to the OLD address (the new one
     * gets the confirmation link instead).
     */
    public static function emailChanged(User $user, ?string $oldEmail, ?User $changedBy = null): void
    {
        if (blank($oldEmail) || strcasecmp($oldEmail, $user->email) === 0) {
            return;
        }

        $notice = fn (): EmailAddressChanged => new EmailAddressChanged($user->name, $user->email, self::otherPerson($user, $changedBy));

        Mailing::send(fn () => $user->notify($notice()));

        if (Mailing::delivers()) {
            Mailing::send(fn () => Notification::route('mail', $oldEmail)
                ->notify($notice()->locale($user->preferredLocale())));
        }
    }

    /**
     * Left to the background jobs, for addresses without an account too: the
     * login page then does the same work either way, so its response time
     * does not show whether an address is registered. Once per address and
     * LOCKOUT_QUIET_SECONDS, so repeated attempts cannot fill the queue or an
     * inbox; the address is only kept as a keyed hash.
     */
    public static function lockedOut(string $email, ?User $user): void
    {
        $key = 'notices.login-blocked.'.hash_hmac('sha256', Str::lower($email), (string) config('app.key'));

        if (Cache::add($key, true, self::LOCKOUT_QUIET_SECONDS)) {
            $userId = $user?->id;

            MailQueue::later(fn () => self::tellLockedOut($userId));
        }
    }

    /** The background half of lockedOut(). */
    public static function tellLockedOut(?int $userId): void
    {
        $user = $userId === null ? null : User::query()->find($userId);

        if ($user) {
            Mailing::send(fn () => $user->notify(new LoginBlocked));
        }
    }

    /** Someone tried to register with this person's address; at most one e-mail per quiet period. */
    public static function signUpAttempted(User $user): void
    {
        if (Cache::add('notices.sign-up-attempt.'.$user->id, true, self::LOCKOUT_QUIET_SECONDS)) {
            Mailing::send(fn () => $user->notify(new SignUpAttempted));
        }
    }

    /** The name of whoever made the change, when it was not the account's owner. */
    private static function otherPerson(User $user, ?User $changedBy): ?string
    {
        return $changedBy && ! $changedBy->is($user) ? $changedBy->name : null;
    }
}
