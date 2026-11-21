<?php

namespace App\Notifications;

use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Security notice: the account's password was changed, by its owner (profile,
 * reset link) or by someone who manages users (App\Support\SecurityNotices).
 */
class PasswordChanged extends InAppNotification
{
    /** @param  ?string  $changedBy  name of the person who changed it, when it was not the owner */
    public function __construct(public ?string $changedBy = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels(queued: false);
    }

    public function inAppData(): array
    {
        return ['by' => $this->changedBy];
    }

    public static function message(array $data): InAppMessage
    {
        return new InAppMessage(
            title: filled($data['by'] ?? null)
                ? __(':person changed your password', ['person' => $data['by']])
                : __('Your password was changed'),
            body: __('If this was not you, choose a new password right away and tell the people who run the gallery.'),
            icon: Heroicon::OutlinedKey,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = config('app.name');

        return (new MailMessage)
            ->subject(__('Your password at :site was changed', ['site' => $site]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->changedBy
                ? __(':person changed the password of your account at :site.', ['person' => $this->changedBy, 'site' => $site])
                : __('The password of your account at :site was changed.', ['site' => $site]))
            ->line(__('If this was not you, choose a new password right away and tell the people who run the gallery.'))
            ->action(__('Choose a new password'), route('password.request'));
    }
}
