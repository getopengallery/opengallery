<?php

namespace App\Notifications;

use App\Models\Album;
use App\Models\User;
use App\Support\Inbox;
use Filament\Support\Icons\Heroicon;

/**
 * A notice about one album. Access can end after it was sent, so its title and
 * link are only shown while the reader can still open the album, and a waiting
 * e-mail is dropped once they cannot.
 */
abstract class AlbumNotification extends InAppNotification
{
    public Album $album;

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== 'mail' || ! $notifiable instanceof User) {
            return true;
        }

        return ! $this->album->trashed() && $this->album->isVisibleTo($notifiable);
    }

    public static function messageFor(array $data, ?User $reader): InAppMessage
    {
        if (Inbox::canOpenAlbum($reader, (int) ($data['album'] ?? 0))) {
            return static::message($data);
        }

        return new InAppMessage(
            title: __('This album is no longer available to you'),
            body: __('It was deleted, or you no longer have access to it.'),
            icon: Heroicon::OutlinedPhoto,
            color: 'gray',
        );
    }
}
