<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps logged-in people who still have to do something before using the
 * gallery on the page that tells them what:
 *
 *  1. confirm their e-mail address (User::mustConfirmEmail(), only while
 *     e-mail works): the "verify e-mail" page;
 *  2. wait for an administrator's approval (users.awaiting_approval, see
 *     Admin → Settings → Access): the "waiting for approval" page.
 *
 * Logging out, those pages and the profile (to fix the address or delete
 * the account) stay open. Runs for the site and the admin.
 */
class EnsureAccountIsActive
{
    /** Routes a held account may still use. */
    private const OPEN = [
        'logout',
        'verification.*',
        'approval.notice',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'password.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        $holdingPage = match (true) {
            $user->mustConfirmEmail() => 'verification.notice',
            $user->awaiting_approval => 'approval.notice',
            default => null,
        };

        if ($holdingPage === null) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403);

        return redirect()->route($holdingPage);
    }
}
