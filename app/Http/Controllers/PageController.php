<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Photo;
use App\Support\PageMeta;
use App\Support\PhotoFileResponse;
use App\Support\PhotoLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PageController extends Controller
{
    public function show(Request $request, Page $page): View
    {
        abort_unless($page->isVisibleTo($request->user()), 404);

        return view('pages.show', ['page' => $page, 'meta' => PageMeta::page($page)]);
    }

    /**
     * A photo placed on a page (Photo or Gallery block). Served to everyone
     * who may see the page, even if the photo's albums are private: putting
     * a photo on a published page publishes it there, and only there.
     */
    public function photo(Request $request, Page $page, Photo $photo, string $conversion = 'preview'): Response
    {
        abort_unless($page->isVisibleTo($request->user()), 404);
        abort_unless(in_array($photo->id, $page->embeddedPhotoIds(), true), 404);
        abort_unless(array_key_exists($conversion, Photo::CONVERSION_SIZES), 404);

        $media = $photo->original();
        abort_unless($media && $media->hasGeneratedConversion($conversion), 404);

        $disk = Storage::disk($media->conversions_disk);
        $path = $media->getPathRelativeToRoot($conversion);
        abort_unless($disk->exists($path), 404);

        // Private like every photo file: once the page is unpublished or the photo taken off it, no shared cache keeps serving it.
        return PhotoFileResponse::make($request, $disk, $path, withoutLocation: PhotoLocation::isHiddenFrom($request->user(), $photo, false));
    }
}
