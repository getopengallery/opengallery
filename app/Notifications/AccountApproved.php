<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells someone who signed up that an administrator approved their account. */
class AccountApproved extends Notification
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
            ->subject(__('Your account at :site is ready', ['site' => $site]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your account at :site has been approved. You can now log in and see the albums shared with you.', ['site' => $site]))
            ->action(__('Open :site', ['site' => $site]), route('dashboard'));
    }
}
