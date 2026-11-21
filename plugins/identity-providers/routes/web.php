<?php

use Illuminate\Support\Facades\Route;
use Plugins\IdentityProviders\SignInController;

Route::middleware('web')->prefix('auth/sso')->name('idp.')->group(function () {
    Route::get('{provider}', [SignInController::class, 'redirect'])->name('redirect');
    Route::get('{provider}/callback', [SignInController::class, 'callback'])->name('callback');
    Route::get('{provider}/link', [SignInController::class, 'redirect'])->middleware('auth')->name('link');
    Route::get('{provider}/icon', [SignInController::class, 'icon'])->name('icon');
});
