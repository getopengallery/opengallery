<?php

namespace App\Http\Middleware;

use App\Support\ProductionCaches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps Laravel's and Filament's production caches built on live sites and
 * drops them as soon as they no longer match the code or `.env` (see
 * ProductionCaches). Building happens after the response is sent.
 */
class KeepProductionCachesFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        ProductionCaches::check();

        return $next($request);
    }
}
