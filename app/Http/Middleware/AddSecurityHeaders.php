<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protections for every response. A header a controller set itself
 * (such as the stricter policy on uploaded images) is kept.
 *
 * The content security policy only covers what no theme or plugin needs to
 * change. It does not limit scripts or styles: Alpine and Filament rely on
 * inline code and eval, and themes may load their own files.
 */
class AddSecurityHeaders
{
    private const HEADERS = [
        'Content-Security-Policy' => "base-uri 'self'; object-src 'none'; frame-ancestors 'self'",
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $maxAge = (int) config('gallery.hsts_max_age');

        if ($maxAge > 0 && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}");
        }

        return $response;
    }
}
