<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DocumentationAssetController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SearchEngineController;
use App\Http\Controllers\ThemeAssetController;
use App\Http\Controllers\WebAppController;
use App\Models\Photo;
use App\Support\SiteSettings;
use App\Support\WebApp;
use Illuminate\Support\Facades\Route;

/*
| Files: photos, profile pictures, branding and theme images. One album page
| loads dozens of them, so they skip the full "web" stack (bootstrap/app.php):
|
|   files   the session is read but never saved, no cookies, no CSRF token
|           (ReadOnlySession), for files that depend on who is asking;
|   static  no session at all, for files every visitor gets.
|
| Access checks stay in the controllers.
*/

Route::middleware('files')->group(function () {
    Route::get('/media/{media}/{conversion?}', MediaController::class)->name('media.show');

    Route::get('/pages/{page}/photos/{photo}/{conversion?}', [PageController::class, 'photo'])
        ->whereNumber('photo')
        ->whereIn('conversion', array_keys(Photo::CONVERSION_SIZES))
        ->name('pages.photo');

    Route::middleware('auth')->group(function () {
        Route::get('/avatars/{user}', AvatarController::class)->name('avatars.show');

        Route::get('/help/assets/{path}', DocumentationAssetController::class)
            ->where('path', '.*')
            ->name('docs.asset');
    });
});

Route::middleware('static')->group(function () {
    // Search engines (App\Support\SearchEngines).
    Route::get('/robots.txt', [SearchEngineController::class, 'robots'])->name('search.robots');
    Route::get('/sitemap.xml', [SearchEngineController::class, 'sitemap'])->name('search.sitemap');

    // Link preview images of unlisted and password-protected albums (App\Support\AlbumPreviewImage; no session: the token is the key).
    Route::get('/s/{token}/cover', [AlbumController::class, 'sharedCover'])->name('albums.shared.cover');
    Route::get('/albums/{album}/cover', [AlbumController::class, 'protectedCover'])->name('albums.cover');

    Route::get('/site/{asset}', BrandingController::class)
        ->whereIn('asset', array_keys(SiteSettings::ASSETS))
        ->name('site.asset');

    // The site as an installable app (App\Support\WebApp); the service worker is public/sw.js.
    Route::get('/manifest.webmanifest', [WebAppController::class, 'manifest'])->name('web-app.manifest');
    Route::get('/app-icons/{size}.png', [WebAppController::class, 'icon'])
        ->whereIn('size', array_map('strval', WebApp::ICON_SIZES))
        ->name('web-app.icon');

    Route::get('/theme-media/{path}', [ThemeAssetController::class, 'upload'])
        ->where('path', '.*')
        ->name('theme.upload');

    Route::get('/themes/{theme}/{path}', ThemeAssetController::class)
        ->where('path', '.*')
        ->name('theme.asset');
});
