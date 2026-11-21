<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches the request to the logged-in user's language, else the site
 * default (Admin → Settings). Runs on the site and in the admin panel.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = Locales::for($user instanceof User ? $user : null);

        // Carbon follows setLocale() by itself; number formatting does not.
        app()->setLocale($locale);
        Number::useLocale($locale);

        return $next($request);
    }
}
