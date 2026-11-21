<?php

namespace App\Notifications;

use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Security notice: the account's e-mail address was changed. Sent twice by
 * App\Support\SecurityNotices: by e-mail to the OLD address (an anonymous
 * notifiable; the new one gets the confirmation link), and in the gallery to
 * the account itself.
 */
class EmailAddressChanged extends InAppNotification
{
    /**
     * @param  ?string  $changedBy  name of the person who changed it, when it was not the owner
     */
    public function __construct(
        public string $accountName,
        public string $newEmail,
        public ?string $changedBy = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['database'];
    }

    public function inAppData(): array
    {
        return ['email' => $this->newEmail, 'by' => $this->changedBy];
    }

    public static function message(array $data): InAppMessage
    {
        $email = ['email' => $data['email'] ?? ''];

        return new InAppMessage(
            title: filled($data['by'] ?? null)
                ? __(':person changed your e-mail address', ['person' => $data['by']])
                : __('Your e-mail address was changed'),
            body: __('E-mails now go to :email. If this was not you, tell the people who run the gallery right away.', $email),
            url: route('profile.edit'),
            icon: Heroicon::OutlinedEnvelope,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = config('app.name');

        return (new MailMessage)
            ->subject(__('The e-mail address of your account at :site was changed', ['site' => $site]))
            ->greeting(__('Hello :name,', ['name' => $this->accountName]))
            ->line($this->changedBy
                ? __(':person changed the e-mail address of your account at :site to :email.', ['person' => $this->changedBy, 'site' => $site, 'email' => $this->newEmail])
                : __('The e-mail address of your account at :site was changed to :email.', ['site' => $site, 'email' => $this->newEmail]))
            ->line(__('This is the last e-mail to this address. If you did not make this change, tell the people who run the gallery right away.'));
    }
}
