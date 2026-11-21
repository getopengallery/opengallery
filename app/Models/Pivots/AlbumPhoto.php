<?php

namespace App\Models\Pivots;

use App\Models\Album;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A photo's place in an album. Keeps album covers valid when photos are
 * added to or removed from an album, takes photos out of "Unsorted uploads"
 * once they are in another album, and reports changes made from the photo's
 * side ("Albums: +Holidays") to the activity log.
 */
class AlbumPhoto extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'album_photo';

    /** "Added at" (created_at) is filled in by the database. */
    public $timestamps = false;

    protected static function booted(): void
    {
        static::created(function (AlbumPhoto $link) {
            $album = Album::withTrashed()->find($link->album_id);

            if ($album && $album->cover_photo_id === null) {
                $album->updateQuietly(['cover_photo_id' => $link->photo_id]);
            }

            // A photo that waited in "Unsorted uploads" leaves it once it is in a real album,
            // however it was added (album form, Photos section, media library, photo form).
            if ($album && $album->system_album !== Album::INBOX) {
                Album::withTrashed()->where('system_album', Album::INBOX)
                    ->whereHas('photos', fn ($query) => $query->whereKey($link->photo_id))
                    ->get()
                    ->each(fn (Album $inbox) => $inbox->photos()->detach($link->photo_id));
            }
        });

        static::deleted(function (AlbumPhoto $link) {
            Album::withTrashed()->find($link->album_id)?->releaseCover((int) $link->photo_id);
        });
    }
}
