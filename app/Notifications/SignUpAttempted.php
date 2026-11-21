<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent instead of a sign-up error when someone registers with an address that
 * already has an account, so the form never reveals which addresses exist.
 */
class SignUpAttempted extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = config('app.name');

        return (new MailMessage)
            ->subject(__('Someone tried to sign up at :site with your address', ['site' => $site]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Someone tried to create an account at :site with this e-mail address, but you already have one. Nothing was changed.', ['site' => $site]))
            ->line(__('If this was you, log in or choose a new password. If not, you can ignore this e-mail.'))
            ->action(__('Choose a new password'), route('password.request'));
    }
}
