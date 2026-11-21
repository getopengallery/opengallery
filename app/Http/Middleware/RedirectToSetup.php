<?php

namespace App\Http\Middleware;

use App\Support\Setup\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until OpenGallery is set up, every page leads to the setup wizard
 * (`/setup`). Runs globally, so the site and the admin are covered.
 */
class RedirectToSetup
{
    /** Paths that work before the installation is finished. */
    private const OPEN = ['setup', 'setup/*', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Installation::isInstalled() || $request->is(...self::OPEN)) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 503, 'OpenGallery is not set up yet.');

        return redirect()->to(url('setup'));
    }
}
