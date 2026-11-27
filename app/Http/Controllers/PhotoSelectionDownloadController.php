<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Support\ActivityLog;
use App\Support\AlbumAccess;
use App\Support\PhotoLocation;
use App\Support\PhotoZip;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Support\MediaStream;

/**
 * Photos selected in the media library ("Download" bulk action) as one zip,
 * files named after the photo titles. The action keeps the chosen ids in the
 * session under a one-time key, since a selection can be too long for a URL.
 * Every id is checked again: only photos the user can see and download, and
 * without GPS data where it is hidden from them.
 */
class PhotoSelectionDownloadController extends Controller
{
    /** Session key prefix of a pending download. */
    public const SESSION_KEY = 'photo-downloads';

    /**
     * Keeps the ids for one download and returns its key.
     *
     * @param  array<int, int>  $photoIds
     */
    public static function prepare(array $photoIds): string
    {
        $key = Str::random(32);
        session()->put(self::SESSION_KEY.'.'.$key, array_values(array_map(intval(...), $photoIds)));

        return $key;
    }

    public function __invoke(Request $request, string $key): MediaStream
    {
        $ids = $request->session()->pull(self::SESSION_KEY.'.'.$key);
        abort_unless(is_array($ids) && $ids !== [], 404);

        $user = $request->user();
        $photos = Photo::visibleTo($user)
            ->whereKey($ids)
            ->with(['media', 'albums'])
            ->orderBy('photos.id')
            ->get()
            ->filter(fn (Photo $photo): bool => AlbumAccess::canDownload($user, $photo));

        abort_if($photos->isEmpty(), 404);

        ActivityLog::zipDownload(null, $photos->count(), $request);

        return PhotoZip::make(
            __('Photos'),
            $photos,
            withoutLocation: fn (Photo $photo): bool => PhotoLocation::isHiddenFrom($user, $photo),
            namedByTitle: true,
        );
    }
}
