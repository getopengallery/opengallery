<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Album;
use App\Models\User;
use App\Notifications\InAppMessage;
use App\Notifications\InAppNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\Permission\Models\Permission as PermissionModel;
use Throwable;

/**
 * The notifications people find in the gallery (Laravel's `notifications`
 * table): the bell in the admin (App\Livewire\DatabaseNotifications) and the
 * bell and notifications page on the site (NotificationController). Both read
 * every notification through message(), so they say the same.
 */
final class Inbox
{
    /** Notifications older than this many days are removed by the background jobs. */
    public const KEEP_DAYS = 180;

    private const UNREAD_MEMO = 'inbox.unread.';

    private const ALBUM_MEMO = 'inbox.album.';

    /** The text of a stored notification, in the current language. */
    public static function message(DatabaseNotification $notification): InAppMessage
    {
        $data = (array) $notification->data;
        $type = $notification->type;

        if (is_string($type) && is_subclass_of($type, InAppNotification::class)) {
            try {
                return $type::messageFor((array) ($data['data'] ?? []), self::reader($notification));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return new InAppMessage(title: (string) ($data['title'] ?? __('Notification')));
    }

    /**
     * Loads the albums these notifications are about in one query, so showing
     * a page of them does not look up each album on its own.
     *
     * @param  iterable<DatabaseNotification>  $notifications
     */
    public static function loadAlbums(iterable $notifications): void
    {
        $ids = [];

        foreach ($notifications as $notification) {
            $id = (int) (((array) $notification->data)['data']['album'] ?? 0);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return;
        }

        $albums = Album::query()->whereKey($ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            RequestMemo::instance()->remember(self::ALBUM_MEMO.$id, fn () => $albums->get($id));
        }
    }

    /** Whether $reader can still open the album a notification is about. */
    public static function canOpenAlbum(?User $reader, int $albumId): bool
    {
        $album = RequestMemo::instance()->remember(self::ALBUM_MEMO.$albumId, fn () => Album::query()->find($albumId));

        return $reader !== null && $album !== null && $album->isVisibleTo($reader);
    }

    /** Unread notifications of $user, counted once per request (the header may show the bell twice). */
    public static function unreadCount(User $user): int
    {
        return RequestMemo::instance()->remember(
            self::UNREAD_MEMO.$user->id,
            fn (): int => $user->unreadNotifications()->count(),
        );
    }

    /**
     * Marks the unread notifications of $type whose stored value $key is
     * $value as read, for everyone: they are about something that has been
     * dealt with (an approved account, a fixed problem).
     */
    public static function markHandled(string $type, string $key, int|string $value): int
    {
        return DatabaseNotification::query()
            ->where('type', $type)
            ->where("data->data->{$key}", $value)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Removes the notifications of $type whose stored value $key is $value, for everyone. */
    public static function forget(string $type, string $key, int|string $value): int
    {
        return DatabaseNotification::query()
            ->where('type', $type)
            ->where("data->data->{$key}", $value)
            ->delete();
    }

    /** Removes notifications older than KEEP_DAYS (task of App\Support\BackgroundJobs). */
    public static function prune(): int
    {
        return DatabaseNotification::query()
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->delete();
    }

    /**
     * Everyone who holds $permission (directly or through a role) and every
     * super administrator, the people a notice about that area is for.
     *
     * @return Collection<int, User>
     */
    public static function peopleWho(Permission $permission, ?User $except = null): Collection
    {
        $exists = PermissionModel::query()->where('name', $permission->value)->exists();

        return User::query()
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roles) => $roles->where('name', Role::SuperAdmin->value))
                ->when($exists, fn ($query) => $query->orWhere(fn ($holders) => $holders->permission($permission->value))))
            ->get();
    }

    /** Who a notification belongs to, usually the person logged in (saves a query per notification). */
    private static function reader(DatabaseNotification $notification): ?User
    {
        $user = auth()->user();

        if ($user instanceof User
            && (int) $notification->notifiable_id === $user->id
            && $notification->notifiable_type === $user->getMorphClass()) {
            return $user;
        }

        return $notification->notifiable instanceof User ? $notification->notifiable : null;
    }
}
