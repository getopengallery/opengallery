<?php

namespace App\Http\Controllers;

use App\Support\Inbox;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The site's notifications page (the bell in the header, x-notification-bell):
 * the same notifications as the admin's bell (App\Support\Inbox). Opening the
 * page marks the notifications it shows as read; they stay highlighted for
 * this one visit.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->notifications()->paginate(20);
        Inbox::loadAlbums($notifications->getCollection());

        $items = $notifications->getCollection()->map(fn (DatabaseNotification $notification) => [
            'message' => Inbox::message($notification),
            'unread' => $notification->unread(),
            'at' => $notification->created_at,
        ]);

        $unreadIds = $notifications->getCollection()->filter->unread()->modelKeys();

        if ($unreadIds !== []) {
            $request->user()->notifications()->whereKey($unreadIds)->update(['read_at' => now()]);
        }

        return view('notifications.index', ['notifications' => $notifications, 'items' => $items]);
    }
}
