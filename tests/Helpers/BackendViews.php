<?php

use App\Support\UpdateCheck;
use Illuminate\Support\Facades\Http;

/** Runs an update check against a faked GitHub that offers $version as the latest stable release. */
function backendOfferUpdate(string $version): void
{
    Http::preventStrayRequests();
    config(['gallery.update_repository' => 'getopengallery/opengallery']);
    turnOnUpdateChecks();
    Http::fake([UPDATE_API => Http::response([githubRelease("v{$version}")])]);
    UpdateCheck::run();
}
