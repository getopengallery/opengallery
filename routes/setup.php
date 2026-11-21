<?php

use App\Http\Controllers\SetupController;
use App\Http\Middleware\EnsureSetupIsOpen;
use Illuminate\Support\Facades\Route;

/*
| The web setup wizard. Only open until the installation is finished
| (App\Support\Setup\Installation); every other page leads here until then
| (App\Http\Middleware\RedirectToSetup).
*/

Route::middleware(EnsureSetupIsOpen::class)->prefix('setup')->name('setup.')->group(function () {
    Route::get('/', [SetupController::class, 'welcome'])->name('welcome');
    Route::post('/', [SetupController::class, 'start'])->name('start');

    Route::get('database', [SetupController::class, 'database'])->name('database');
    Route::post('database', [SetupController::class, 'saveDatabase'])->middleware('throttle:10,1');

    Route::get('administrator', [SetupController::class, 'administrator'])->name('administrator');
    Route::post('administrator', [SetupController::class, 'saveAdministrator']);

    Route::get('unlock', [SetupController::class, 'unlock'])->name('unlock');
    Route::post('unlock', [SetupController::class, 'confirmUnlock'])->middleware('throttle:6,1');

    Route::get('site', [SetupController::class, 'site'])->name('site');
    Route::post('site', [SetupController::class, 'saveSite']);

    Route::get('demo', [SetupController::class, 'demo'])->name('demo');
    Route::post('demo', [SetupController::class, 'finish'])->name('finish');
});
