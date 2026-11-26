<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\BackgroundJobController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PhotoUploadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebAppController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AlbumController::class, 'index'])->name('home');

Route::get('/albums/{album}', [AlbumController::class, 'show'])->name('albums.show');
Route::get('/albums/{album}/photos', [AlbumController::class, 'photos'])->name('albums.photos');
Route::post('/albums/{album}/unlock', [AlbumController::class, 'unlock'])
    ->middleware('throttle:10,1')
    ->name('albums.unlock');
Route::get('/albums/{album}/download', [AlbumController::class, 'download'])
    ->middleware('throttle:10,1')
    ->name('albums.download');
Route::get('/s/{token}', [AlbumController::class, 'shared'])->name('albums.shared');

Route::get('/pages/{page}', [PageController::class, 'show'])->name('pages.show');

// Shown by the service worker (public/sw.js) when a page cannot be loaded without a connection.
Route::get('/offline', [WebAppController::class, 'offline'])->name('web-app.offline');

// Photos, profile pictures, branding and theme images: routes/files.php.

// Background jobs started by visitors' browsers (AJAX) or a webcron service; exempt from CSRF in bootstrap/app.php.
Route::post('/background-jobs', [BackgroundJobController::class, 'ajax'])
    ->middleware('throttle:6,1')
    ->name('background-jobs.ajax');
Route::match(['get', 'post'], '/cron/{token}', [BackgroundJobController::class, 'webcron'])
    ->middleware('throttle:10,1')
    ->name('background-jobs.webcron');

// Unconfirmed and unapproved accounts are held back by EnsureAccountIsActive (web group).
Route::get('/my-albums', [AlbumController::class, 'mine'])
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:password-check')
        ->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->middleware('throttle:password-check')
        ->name('profile.destroy');
    Route::get('/profile/photos', [ProfileController::class, 'downloadPhotos'])
        ->middleware('throttle:5,1')
        ->name('profile.photos.download');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

    // Each person's private Favorites and the heart in the lightbox (Settings → Photos).
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('/favorites/{photo}', [FavoriteController::class, 'toggle'])
        ->middleware('throttle:60,1')
        ->name('favorites.toggle');

    // Chunked photo uploads from the media picker (no throttle: big batches send many chunks).
    Route::post('/uploads/photos', PhotoUploadController::class)->name('uploads.photos');
});

require __DIR__.'/auth.php';
require __DIR__.'/setup.php';
