<?php

namespace App\Notifications;

use App\Enums\Visibility;
use App\Models\AlbumGuest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites someone without an account to a restricted album shared with them
 * by e-mail (App\Support\AlbumGuests). Waits in App\Support\MailQueue and is
 * dropped when the guest was removed or the album is no longer shared that way.
 */
class AlbumGuestInvited extends Notification
{
    public function __construct(public AlbumGuest $guest, public string $url, public ?string $invitedBy = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $album = $this->guest->album;

        return $album !== null && ! $album->trashed() && $album->visibility === Visibility::Restricted && ! $album->accessExpired();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $album = $this->guest->album;
        $site = config('app.name');

        return (new MailMessage)
            ->subject(__('":album" was shared with you', ['album' => $album->title]))
            ->greeting(__('Hello,'))
            ->line($this->invitedBy
                ? __(':person shared the album ":album" with you at :site.', ['person' => $this->invitedBy, 'album' => $album->title, 'site' => $site])
                : __('The album ":album" was shared with you at :site.', ['album' => $album->title, 'site' => $site]))
            ->line(__('You do not need an account. When you open the link, we send you a code by e-mail to confirm it is you.'))
            ->action(__('Open album'), $this->url)
            ->line(__('This link is meant for you only. Please do not forward it.'));
    }
}
