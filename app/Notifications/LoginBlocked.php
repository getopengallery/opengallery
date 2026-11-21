<?php

namespace App\Notifications;

use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Security notice: logging in to the account was blocked for a while after
 * too many wrong passwords (Laravel's Lockout event). Sent at most once per
 * SecurityNotices::LOCKOUT_QUIET_SECONDS, so nobody can flood an inbox.
 */
class LoginBlocked extends InAppNotification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels();
    }

    public function inAppData(): array
    {
        return [];
    }

    public static function message(array $data): InAppMessage
    {
        return new InAppMessage(
            title: __('Logging in to your account was blocked for a short time'),
            body: __('Someone typed a wrong password for your account several times. Nobody got in. If this was not you, make sure your password is long and used nowhere else.'),
            url: route('profile.edit'),
            icon: Heroicon::OutlinedShieldExclamation,
            color: 'warning',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = config('app.name');

        return (new MailMessage)
            ->subject(__('Several failed logins to your account at :site', ['site' => $site]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Someone typed a wrong password for your account at :site several times, so logging in is blocked for a short time.', ['site' => $site]))
            ->line(__('Nobody got in. If this was you and you forgot your password, choose a new one. If it was not you, make sure your password is long and used nowhere else.'))
            ->action(__('Choose a new password'), route('password.request'));
    }
}
