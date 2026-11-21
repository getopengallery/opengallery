<?php

namespace App\Notifications;

use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;

/** Tells someone that joining a group gave them access to albums (App\Support\GroupNotices): one notice, however many albums. */
class GroupAlbumsShared extends InAppNotification
{
    public function __construct(public string $addedBy, public int $albums) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels();
    }

    public function inAppData(): array
    {
        return ['by' => $this->addedBy, 'count' => $this->albums];
    }

    public static function message(array $data): InAppMessage
    {
        return new InAppMessage(
            title: trans_choice(':person added you to a group with :count album|:person added you to a group with :count albums', (int) ($data['count'] ?? 0), ['person' => $data['by'] ?? '']),
            body: __('You find the albums on your "My albums" page, under "Shared with you".'),
            url: route('dashboard'),
            icon: Heroicon::OutlinedUserGroup,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(trans_choice(':person added you to a group with :count album|:person added you to a group with :count albums', $this->albums, ['person' => $this->addedBy]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(trans_choice(':person added you to a group at :site. You can now see :count more album.|:person added you to a group at :site. You can now see :count more albums.', $this->albums, [
                'person' => $this->addedBy,
                'site' => config('app.name'),
            ]))
            ->action(__('Open my albums'), route('dashboard'))
            ->line(__('You find the albums on your "My albums" page, under "Shared with you".'));
    }
}
