<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Support\AlbumAccess;
use App\Support\Favorites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The heart in the lightbox and each person's private Favorites (App\Support\Favorites). */
class FavoriteController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(Favorites::enabledFor($request->user()), 404);

        $photos = Favorites::page($request->user(), AlbumController::PHOTOS_PER_PAGE)->withQueryString();

        return view('favorites.index', ['photos' => $photos]);
    }

    /** Likes or unlikes a photo the person may see; answers with the new state. */
    public function toggle(Request $request, Photo $photo): JsonResponse
    {
        abort_unless(Favorites::enabledFor($request->user()), 404);
        // Unliking is always possible; liking only photos the person may see.
        abort_unless(Favorites::has($request->user(), $photo) || AlbumAccess::canViewPhoto($request->user(), $photo), 404);

        return response()->json(['favorite' => Favorites::toggle($request->user(), $photo)]);
    }
}
