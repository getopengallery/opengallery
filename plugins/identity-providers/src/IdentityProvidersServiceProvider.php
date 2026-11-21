<?php

namespace Plugins\IdentityProviders;

use App\Extensions\LoginOptions;
use Illuminate\Support\ServiceProvider;

class IdentityProvidersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Buttons on the login page; resolved lazily when the page renders.
        $this->app->make(LoginOptions::class)->resolveUsing(
            fn () => $this->app->make(ProviderRepository::class)->loginOptions(),
        );
    }
}
