<?php

namespace App\Notifications;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells people who manage users that someone created an account (Admin →
 * Settings → Access), in the gallery and by e-mail. Marked as read for
 * everyone once the account is approved (App\Support\AccountApproval) and
 * removed when it is deleted.
 */
class AccountCreated extends InAppNotification
{
    public function __construct(public User $account) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels();
    }

    public function inAppData(): array
    {
        return [
            'account' => $this->account->id,
            'name' => $this->account->name,
            'email' => $this->account->email,
            'awaiting' => (bool) $this->account->awaiting_approval,
        ];
    }

    public static function message(array $data): InAppMessage
    {
        $awaiting = (bool) ($data['awaiting'] ?? false);
        $person = ['name' => $data['name'] ?? '', 'email' => $data['email'] ?? ''];

        return new InAppMessage(
            title: $awaiting
                ? __(':name is waiting for approval', $person)
                : __(':name created an account', $person),
            body: $awaiting
                ? __(':email signed up and only sees a "waiting for approval" page until someone approves the account.', $person)
                : __(':email signed up. Give them a role or add them to groups to share more.', $person),
            url: UserResource::getUrl('edit', ['record' => $data['account'] ?? 0]),
            icon: Heroicon::OutlinedUserPlus,
            color: $awaiting ? 'warning' : 'primary',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('New account at :site: :name', ['site' => config('app.name'), 'name' => $this->account->name]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':name (:email) has created an account at :site.', [
                'name' => $this->account->name,
                'email' => $this->account->email,
                'site' => config('app.name'),
            ]))
            ->line($this->account->awaiting_approval
                ? __('The account waits for your approval. Until then the person only sees a "waiting for approval" page.')
                : __('New accounts can only see public albums and albums for registered users. Give them a role or add them to groups to share more.'))
            ->action($this->account->awaiting_approval ? __('Review and approve') : __('Open in the admin'), UserResource::getUrl('edit', ['record' => $this->account]));
    }
}
