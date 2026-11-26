<?php

namespace App\Notifications;

use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Security notice: someone who manages users signed the account out on one
 * or more devices (App\Support\SecurityNotices). Not sent when people sign
 * out their own sessions.
 */
class SessionsSignedOut extends InAppNotification
{
    /** @param  string  $signedOutBy  name of the person who signed the account out */
    public function __construct(public string $signedOutBy) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels(queued: false);
    }

    public function inAppData(): array
    {
        return ['by' => $this->signedOutBy];
    }

    public static function message(array $data): InAppMessage
    {
        return new InAppMessage(
            title: __(':person signed you out on other devices', ['person' => $data['by'] ?? __('Someone')]),
            body: __('Sign in again on those devices to keep using them. If you did not expect this, choose a new password.'),
            url: route('profile.edit'),
            icon: Heroicon::OutlinedComputerDesktop,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = config('app.name');

        return (new MailMessage)
            ->subject(__('You were signed out at :site', ['site' => $site]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':person signed your account at :site out on one or more devices.', ['person' => $this->signedOutBy, 'site' => $site]))
            ->line(__('Sign in again on those devices to keep using them. If you did not expect this, choose a new password.'))
            ->action(__('Open your profile'), route('profile.edit'));
    }
}
