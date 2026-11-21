<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use App\Support\Setup\Installation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

/**
 * The setup wizard only exists until the installation is finished; after
 * that `/setup` is not found. Shows the wizard in the language chosen on
 * its first page, else in the browser's language, else in the configured
 * default (APP_LOCALE, English).
 */
class EnsureSetupIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Installation::isInstalled(), 404);

        $locale = $request->session()->get('setup.locale');

        if (! Locales::isAvailable($locale)) {
            // Symfony answers with the first entry when the browser sends no language or none we have.
            $locale = $request->getPreferredLanguage(array_values(array_unique([Locales::default(), ...array_keys(Locales::available())])));
        }

        app()->setLocale($locale);
        Number::useLocale($locale);

        return $next($request);
    }
}
