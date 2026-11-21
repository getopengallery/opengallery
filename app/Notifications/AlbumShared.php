<?php

namespace App\Notifications;

use App\Models\Album;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;

/** Tells someone that an album was shared with them (directly or through a group), in the gallery and by e-mail. */
class AlbumShared extends AlbumNotification
{
    public function __construct(public Album $album, public string $sharedBy) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels();
    }

    public function inAppData(): array
    {
        return ['album' => $this->album->id, 'slug' => $this->album->slug, 'title' => $this->album->title, 'by' => $this->sharedBy];
    }

    public static function message(array $data): InAppMessage
    {
        return new InAppMessage(
            title: __(':person shared ":album" with you', ['person' => $data['by'] ?? '', 'album' => $data['title'] ?? '']),
            body: __('You find it on your "My albums" page too, under "Shared with you".'),
            url: filled($data['slug'] ?? null) ? route('albums.show', $data['slug']) : null,
            icon: Heroicon::OutlinedPhoto,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':person shared ":album" with you', ['person' => $this->sharedBy, 'album' => $this->album->title]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':person shared the album ":album" with you at :site.', [
                'person' => $this->sharedBy,
                'album' => $this->album->title,
                'site' => config('app.name'),
            ]))
            ->action(__('Open the album'), route('albums.show', $this->album))
            ->line(__('You find it on your "My albums" page too, under "Shared with you".'));
    }
}
