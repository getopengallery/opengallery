<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Mailing;
use App\Support\MailQueue;
use Illuminate\Notifications\Notification;

/**
 * A notification that is shown in the gallery (the bell in the admin and on
 * the site, App\Support\Inbox) and, where the subclass asks for it, e-mailed.
 *
 * Only plain values are stored (inAppData()); the text is made from them each
 * time it is shown (message()), so it follows the reader's current language.
 * The stored title is only a fallback for when the class no longer exists.
 */
abstract class InAppNotification extends Notification
{
    /** Tries of a waiting e-mail (App\Support\MailQueue). */
    public int $tries = MailQueue::TRIES;

    /** A waiting e-mail about something that was deleted is dropped. */
    public bool $deleteWhenMissingModels = true;

    /**
     * The values message() needs later: ids, names, keys, counts. No models.
     *
     * @return array<string, scalar|null>
     */
    abstract public function inAppData(): array;

    /**
     * The text for what inAppData() returned when the notification was sent.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public static function message(array $data): InAppMessage;

    /**
     * What $reader sees now. Notices whose subject can go away override this.
     *
     * @param  array<string, mixed>  $data
     */
    public static function messageFor(array $data, ?User $reader): InAppMessage
    {
        return static::message($data);
    }

    /** @return list<int> seconds before each further try of a waiting e-mail */
    public function backoff(): array
    {
        return MailQueue::RETRY_SECONDS;
    }

    /**
     * In the gallery always; by e-mail too when $mail and e-mail works. The
     * gallery comes first, so a failing mail server never loses it. The e-mail
     * waits in the mail queue unless $queued is false (urgent, one recipient).
     *
     * @return list<string>
     */
    protected function channels(bool $mail = true, bool $queued = true): array
    {
        if (! $mail || ! Mailing::delivers()) {
            return ['database'];
        }

        return ['database', $queued ? QueuedMailChannel::class : 'mail'];
    }

    /** @return array{format: string, title: string, data: array<string, scalar|null>} */
    public function toDatabase(object $notifiable): array
    {
        $data = $this->inAppData();

        return [
            // Filament's notification panel only lists records in its format.
            'format' => 'filament',
            'title' => static::message($data)->title,
            'data' => $data,
        ];
    }
}
