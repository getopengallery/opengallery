<?php

namespace App\Support;

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\User;
use App\Notifications\AlbumShared;

/**
 * Tells people when a restricted album is shared with them, directly or
 * through one of their groups ("Tell people when they get access" in the
 * album form): in the gallery, and by e-mail while e-mail works. Only people
 * who gain access get a message, never the person sharing.
 */
class ShareNotices
{
    /**
     * Everyone the album is shared with now, directly or through a group.
     *
     * @return list<int> user ids
     */
    public static function recipientIds(Album $album): array
    {
        return User::query()
            ->where(fn ($query) => $query
                ->whereHas('sharedAlbums', fn ($albums) => $albums->whereKey($album->id))
                ->orWhereHas('groups.albums', fn ($albums) => $albums->whereKey($album->id)))
            ->pluck('id')
            ->all();
    }

    /**
     * Tells everyone who has access now but did not have it in $before.
     * told: people told in the gallery; sent: e-mails on their way (they wait
     * in App\Support\MailQueue); failed: people who could not be told.
     *
     * @param  list<int>  $before  recipientIds() before the change
     * @return array{told: int, sent: int, failed: int}
     */
    public static function notifyNew(Album $album, array $before, User $sharedBy): array
    {
        if ($album->visibility !== Visibility::Restricted) {
            return ['told' => 0, 'sent' => 0, 'failed' => 0];
        }

        return Mailing::notifyEach(
            array_diff(self::recipientIds($album), $before, [$sharedBy->id]),
            fn () => new AlbumShared($album, $sharedBy->name),
        );
    }
}
