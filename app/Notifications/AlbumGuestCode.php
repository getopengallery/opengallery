<?php

namespace App\Notifications;

use App\Models\Album;
use App\Support\AlbumGuests;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The 6-digit code someone without an account enters to open an album shared with them by e-mail. Sent right away. */
class AlbumGuestCode extends Notification
{
    public function __construct(public Album $album, public string $code) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your code for ":album": :code', ['album' => $this->album->title, 'code' => $this->code]))
            ->greeting(__('Hello,'))
            ->line(__('Enter this code to open the album ":album":', ['album' => $this->album->title]))
            ->line('**'.$this->code.'**')
            ->line(trans_choice('The code works for :count minute.|The code works for :count minutes.', AlbumGuests::CODE_MINUTES))
            ->line(__('If you did not try to open this album, you can ignore this e-mail.'));
    }
}
