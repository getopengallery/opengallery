<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Support\AlbumAccess;
use App\Support\PhotoFileResponse;
use App\Support\PhotoLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams photo files from the private "gallery" disk after checking that
 * the visitor may see the album. Never link to the disk directly. The
 * original file (no conversion) is only for people who may download it
 * (AlbumAccess::canDownload()); everyone else gets the conversions (Photo::CONVERSION_SIZES).
 */
class MediaController extends Controller
{
    public function __invoke(Request $request, Media $media, ?string $conversion = null): Response
    {
        $photo = $media->model_type === Photo::class ? Photo::withTrashed()->find($media->model_id) : null;

        abort_unless($photo instanceof Photo, 404);

        // Visible through any album the visitor may see (including unlocked share
        // links). Photos in the trash, or only in trashed albums, are only for
        // people who may edit them.
        abort_unless(AlbumAccess::canViewPhoto($request->user(), $photo), 404);

        if ($conversion !== null) {
            abort_unless(array_key_exists($conversion, Photo::CONVERSION_SIZES) && $media->hasGeneratedConversion($conversion), 404);
            $path = $media->getPathRelativeToRoot($conversion);
        } else {
            abort_unless(AlbumAccess::canDownload($request->user(), $photo), 404);
            $path = $media->getPathRelativeToRoot();
        }

        $disk = Storage::disk($conversion ? $media->conversions_disk : $media->disk);
        abort_unless($disk->exists($path), 404);

        // ?download=1 (originals only, see above) saves the file instead of showing it.
        return PhotoFileResponse::make(
            $request,
            $disk,
            $path,
            $conversion === null && $request->boolean('download') ? $media->file_name : null,
            PhotoLocation::isHiddenFrom($request->user(), $photo, $conversion === null),
        );
    }
}
