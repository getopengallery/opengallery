<?php

namespace App\Notifications;

use App\Support\Invitations;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to a new account (or a fresh set-password link): the person
 * chooses a password on the link and is logged in. Sent synchronously, in
 * the recipient's language.
 */
class UserInvited extends Notification
{
    public function __construct(public string $url, public ?string $invitedBy = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = config('app.name');

        return (new MailMessage)
            ->subject(__('Your account at :site', ['site' => $site]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->invitedBy
                ? __(':person has set up an account for you at :site.', ['person' => $this->invitedBy, 'site' => $site])
                : __('An account has been set up for you at :site.', ['site' => $site]))
            ->line(__('Choose your password to log in:'))
            ->action(__('Choose your password'), $this->url)
            ->line(trans_choice('The link works for :count day.|The link works for :count days.', Invitations::validForDays()));
    }
}
