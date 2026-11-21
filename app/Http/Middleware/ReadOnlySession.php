<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instead of StartSession for photo files (routes/files.php): the visitor's
 * session is read, so the logged-in person and the albums unlocked in this
 * session (share links, passwords) count as on every page, but it is never
 * saved. No session row is written or created and no cookie is sent, which
 * matters because one album page loads dozens of photos at once.
 *
 * A "remember me" cookie is not used here: signing in again happens on the
 * next page view (which saves the session), not once per photo.
 */
class ReadOnlySession
{
    public function __construct(private SessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->driver();
        $session->setId($request->cookies->get($session->getName()));
        $session->setRequestOnHandler($request);
        $session->start();
        $request->setLaravelSession($session);

        $guard = auth()->guard();
        if ($guard instanceof SessionGuard) {
            $request->cookies->remove($guard->getRecallerName());
        }

        return $next($request);
    }
}
