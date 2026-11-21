<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * The password check of AuthenticateSession for photo files: a session from
 * before the last password change counts as a guest here. It is not sent to
 * the login page, because an image cannot follow that redirect and public
 * photos should still load. The next page view ends the session.
 */
class IgnoreOutdatedFileSession extends AuthenticateSession
{
    /** @param  Request  $request */
    protected function logout($request): void
    {
        $request->session()->flush();
        $this->guard()->forgetUser();
    }
}
