<?php

namespace App\Livewire;

use App\Support\DateFormat;
use App\Support\Inbox;
use Filament\Livewire\DatabaseNotifications as FilamentDatabaseNotifications;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;

/**
 * The admin's notification panel: Filament's own, opened by a bell in the top
 * bar (filament.partials.topbar-account, styled like the light/dark switch).
 * The text comes from App\Support\Inbox (in the reader's language, the same
 * as on the site) and dates use the gallery's one format, not "2 hours ago".
 */
class DatabaseNotifications extends FilamentDatabaseNotifications
{
    #[Unexposed]
    public function getNotifications(): DatabaseNotificationCollection|Paginator
    {
        $notifications = parent::getNotifications();
        Inbox::loadAlbums($notifications instanceof Paginator ? $notifications->items() : $notifications);

        return $notifications;
    }

    #[Unexposed]
    public function getNotification(DatabaseNotification $notification): Notification
    {
        return Inbox::message($notification)
            ->toFilament()
            ->id($notification->getKey())
            ->date(DateFormat::dateTime($notification->getAttributeValue('created_at')));
    }

    #[Unexposed]
    public function getTrigger(): ?View
    {
        return view('filament.partials.notifications-trigger');
    }
}
