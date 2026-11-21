<?php

namespace App\Notifications;

use App\Support\Mailing;
use App\Support\MailQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;

/**
 * The mail channel for notices that can go to many people: the e-mail waits
 * in App\Support\MailQueue while the notice in the gallery is stored at once.
 * The test mailbox has no sending limit and is read right away, so it skips
 * the queue.
 */
class QueuedMailChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (Mailing::usesTestMailbox()) {
            app(ChannelManager::class)->sendNow($notifiable, $notification, ['mail']);

            return;
        }

        MailQueue::push($notifiable, $notification);
    }
}
