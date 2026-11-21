<?php

namespace App\Notifications;

use App\Enums\CollaboratorRole;
use App\Models\Album;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;

/** Tells someone they can now work on an album (directly or through a group), in the gallery and by e-mail. */
class AlbumCollaboratorAdded extends AlbumNotification
{
    public function __construct(public Album $album, public string $addedBy, public CollaboratorRole $role) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels();
    }

    public function inAppData(): array
    {
        return ['album' => $this->album->id, 'slug' => $this->album->slug, 'title' => $this->album->title, 'by' => $this->addedBy, 'role' => $this->role->value];
    }

    public static function message(array $data): InAppMessage
    {
        return new InAppMessage(
            title: __(':person invited you to work on ":album"', ['person' => $data['by'] ?? '', 'album' => $data['title'] ?? '']),
            body: __('Your role: :role. You find the album on your "My albums" page, under "Shared with you".', [
                'role' => CollaboratorRole::tryFrom((string) ($data['role'] ?? ''))?->getLabel() ?? '',
            ]),
            url: filled($data['slug'] ?? null) ? route('albums.show', $data['slug']) : null,
            icon: Heroicon::OutlinedUserGroup,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':person invited you to work on ":album"', ['person' => $this->addedBy, 'album' => $this->album->title]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':person invited you to work on the album ":album" at :site.', [
                'person' => $this->addedBy,
                'album' => $this->album->title,
                'site' => config('app.name'),
            ]))
            ->line(__('Your role: :role. You find the album on your "My albums" page, under "Shared with you".', ['role' => $this->role->getLabel()]))
            ->line($this->role->getDescription())
            ->action(__('Open the album'), route('albums.show', $this->album));
    }
}
