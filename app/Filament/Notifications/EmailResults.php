<?php

namespace App\Filament\Notifications;

use App\Models\User;
use App\Support\Mailing;
use Filament\Notifications\Notification;

/**
 * Tells the person in the admin how e-mails they caused went: sent, kept in
 * the test mailbox, or failed (then with what to do instead). Shared by
 * every page that sends them, so the wording is the same everywhere.
 */
class EmailResults
{
    /**
     * Share notices (App\Support\ShareNotices) after creating or saving an album.
     *
     * @param  array{told: int, sent: int, failed: int}  $result
     */
    public static function shareNotices(array $result): void
    {
        if ($result['sent'] === 0 && $result['told'] > 0) {
            Notification::make()
                ->title(trans_choice(':count person was told in the gallery|:count people were told in the gallery', $result['told']))
                ->success()
                ->send();
        }

        if ($result['sent'] > 0) {
            Notification::make()
                ->title(Mailing::usesTestMailbox()
                    ? trans_choice('The e-mail to :count person is in the test mailbox|The e-mails to :count people are in the test mailbox', $result['sent'])
                    : trans_choice(':count person will get an e-mail shortly|:count people will get an e-mail shortly', $result['sent']))
                ->success()
                ->send();
        }

        if ($result['failed'] > 0) {
            Notification::make()
                ->title(trans_choice('The e-mail to :count person could not be sent|The e-mails to :count people could not be sent', $result['failed']))
                ->body(__('Check the e-mail settings. The album is shared all the same.'))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * An invitation or password link (App\Support\Invitations): whether it went
     * out, or the link to pass on (no e-mail set up, or the mail server failed).
     *
     * @param  array{url: string, sent: bool}  $invitation
     */
    public static function invitation(User $user, array $invitation): void
    {
        if ($invitation['sent']) {
            Notification::make()
                ->title(Mailing::usesTestMailbox()
                    ? __('The invitation for :email is in the test mailbox', ['email' => $user->email])
                    : __('Invitation sent to :email', ['email' => $user->email]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('The invitation was not e-mailed'))
            ->body(__('Send :name this link to choose a password: :url', ['name' => $user->name, 'url' => $invitation['url']]))
            ->warning()
            ->persistent()
            ->send();
    }
}
