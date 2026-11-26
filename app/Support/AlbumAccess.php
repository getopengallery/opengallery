<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Whether a visitor may open an album (and see its photos), combining:
 *
 *  - direct access (owner, staff, people/groups a restricted album is shared
 *    with): always, regardless of password and end date;
 *  - the album's end date: afterwards nobody else gets in;
 *  - its visibility (AlbumPolicy) or the unlisted share link opened earlier
 *    in this session (a new link invalidates that);
 *  - its password, if set: entered earlier in this session. Changing the
 *    password invalidates earlier unlocks.
 */
class AlbumAccess
{
    private const LINKS = 'unlocked_albums';

    private const PASSWORDS = 'album_passwords';

    /**
     * Whether $user may see $photo on the site: through any of its albums
     * that is not in the trash and that they may open (as MediaController
     * serves its thumbnails). Trashed photos are only for people who may edit them.
     */
    public static function canViewPhoto(?User $user, Photo $photo): bool
    {
        return (! $photo->trashed() && $photo->activeAlbums()->contains(fn (Album $album) => self::canView($user, $album)))
            || (bool) $user?->can('update', $photo);
    }

    public static function canView(?User $user, Album $album): bool
    {
        if ($album->hasDirectAccess($user)) {
            return true;
        }

        return self::reachable($user, $album) && (! $album->hasPassword() || self::passwordEntered($album));
    }

    /**
     * Whether $user may download the original file of $photo: people who may
     * edit it (always, also in the admin), and, while downloads are on
     * (Settings → Photos), visitors of an album it is in that allows downloads
     * (and that they may open). Everyone else only gets the thumbnail and preview.
     */
    public static function canDownload(?User $user, Photo $photo): bool
    {
        if ($user?->can('update', $photo)) {
            return true;
        }

        return SiteSettings::downloadsEnabled()
            && ! $photo->trashed()
            && $photo->activeAlbums()->contains(fn (Album $album) => $album->allow_downloads && self::canView($user, $album));
    }

    /** The visitor could open the album after entering its password. */
    public static function requiresPassword(?User $user, Album $album): bool
    {
        return ! $album->hasDirectAccess($user)
            && $album->hasPassword()
            && self::reachable($user, $album)
            && ! self::passwordEntered($album);
    }

    public static function unlock(Album $album): void
    {
        session()->put(self::LINKS.'.'.$album->id, $album->shareLinkFingerprint());
    }

    public static function rememberPassword(Album $album): void
    {
        session()->put(self::PASSWORDS.'.'.$album->id, $album->passwordFingerprint());
    }

    /** Visible by its visibility or share link, and not past its end date. */
    private static function reachable(?User $user, Album $album): bool
    {
        if ($album->accessExpired()) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $album)
            || ($album->share_token !== null && hash_equals($album->shareLinkFingerprint(), (string) session(self::LINKS.'.'.$album->id)));
    }

    private static function passwordEntered(Album $album): bool
    {
        return hash_equals($album->passwordFingerprint(), (string) session(self::PASSWORDS.'.'.$album->id));
    }
}
