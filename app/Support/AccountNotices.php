<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;
use App\Notifications\AccountCreated;

/**
 * Tells the people who manage users that someone created an account
 * (Admin → Settings → Access, SiteSettings::notifiesAboutRegistrations()),
 * and always when the account waits for their approval: in the gallery,
 * and by e-mail while e-mail works. Called for every sign-up, local or
 * through an identity provider.
 */
class AccountNotices
{
    public static function registered(User $account): void
    {
        // With approval on, someone has to know, so the notice always goes out.
        if (! (SiteSettings::notifiesAboutRegistrations() || $account->awaiting_approval)) {
            return;
        }

        foreach (Inbox::peopleWho(Permission::ManageUsers, except: $account) as $manager) {
            Mailing::send(fn () => $manager->notify(new AccountCreated($account)));
        }
    }
}
