<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\IgnoreOutdatedFileSession;
use App\Http\Middleware\KeepProductionCachesFresh;
use App\Http\Middleware\ReadOnlySession;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\UseConfiguredSiteAddress;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Photos and other files, without the full web stack (see routes/files.php).
        then: fn () => Route::group([], __DIR__.'/../routes/files.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Links never take their address from a forged Host header.
        $middleware->prepend(UseConfiguredSiteAddress::class);
        // Browser protections on every response.
        $middleware->append(AddSecurityHeaders::class);
        // Until the installation is finished, every page leads to the setup wizard (/setup).
        $middleware->append(RedirectToSetup::class);
        // Live sites keep Laravel's and Filament's caches built and up to date (ProductionCaches).
        $middleware->append(KeepProductionCachesFresh::class);
        // The user's language (or the site default) for every page, incl. Livewire updates.
        // AuthenticateSession ends other sessions when the password changes.
        $middleware->web(append: [AuthenticateSession::class, SetLocale::class, EnsureAccountIsActive::class]);
        // Files that depend on who asks: the session is read, never saved (no cookies, no CSRF token).
        $middleware->group('files', [
            EncryptCookies::class,
            ReadOnlySession::class,
            IgnoreOutdatedFileSession::class,
            SubstituteBindings::class,
            SetLocale::class,
            EnsureAccountIsActive::class,
        ]);
        // Files every visitor gets: no session at all.
        $middleware->group('static', [SubstituteBindings::class]);
        // The session must be read before "auth" asks who is logged in.
        $middleware->appendToPriorityList(StartSession::class, ReadOnlySession::class);
        // Background job triggers (navigator.sendBeacon and webcron services cannot send a token).
        $middleware->validateCsrfTokens(except: ['background-jobs', 'cron/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
