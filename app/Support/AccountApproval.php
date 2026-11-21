<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\AccountApproved;
use App\Notifications\AccountCreated;

/**
 * "New accounts need approval" (Admin → Settings → Access): people who sign
 * up themselves wait (users.awaiting_approval, EnsureAccountIsActive) until
 * someone who manages users approves them.
 */
class AccountApproval
{
    /** Called for every sign-up (local or through an identity provider). */
    public static function holdIfRequired(User $user): void
    {
        if (SiteSettings::requiresApproval()) {
            // Not a change anyone made: kept out of the activity log.
            $user->forceFill(['awaiting_approval' => true])->saveQuietly();
        }
    }

    /** Lets $user in and tells them by e-mail. Logged as a change to the user. */
    public static function approve(User $user): void
    {
        if (! $user->awaiting_approval) {
            return;
        }

        $user->forceFill(['awaiting_approval' => false])->save();

        // Dealt with: the "waiting for approval" notices of everyone who manages users.
        Inbox::markHandled(AccountCreated::class, 'account', $user->id);

        if (Mailing::delivers()) {
            Mailing::send(fn () => $user->notify(new AccountApproved));
        }
    }
}
