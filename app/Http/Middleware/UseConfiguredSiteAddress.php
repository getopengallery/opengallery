<?php

namespace App\Http\Middleware;

use App\Support\Setup\Installation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Links are built from the request's Host header, which the sender controls.
 * A forged header would put another site's address into password reset and
 * other e-mails, so a request for an address that is not this site's gets
 * its links from APP_URL instead.
 *
 * This site's addresses are APP_URL, its www / non-www twin and
 * GALLERY_TRUSTED_HOSTS. Nothing is pinned during local development, before
 * the installation is finished (the wizard learns the address from the
 * request) or while APP_URL still names this computer.
 */
class UseConfiguredSiteAddress
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public function handle(Request $request, Closure $next): Response
    {
        $address = $this->pinnedAddress($request);

        URL::forceRootUrl($address);
        URL::forceScheme($address === null ? null : parse_url($address, PHP_URL_SCHEME));

        return $next($request);
    }

    private function pinnedAddress(Request $request): ?string
    {
        $configured = rtrim((string) config('app.url'), '/');
        $host = strtolower((string) parse_url($configured, PHP_URL_HOST));

        if ($host === '' || in_array($host, self::LOOPBACK_HOSTS, true) || app()->isLocal()) {
            return null;
        }

        if (in_array(strtolower($request->getHttpHost()), $this->siteHosts($configured, $host), true)) {
            return null;
        }

        return Installation::isInstalled() ? $configured : null;
    }

    /** @return list<string> host names, with the port where APP_URL has one */
    private function siteHosts(string $configured, string $host): array
    {
        $port = parse_url($configured, PHP_URL_PORT);
        $main = $port ? "{$host}:{$port}" : $host;
        $twin = str_starts_with($main, 'www.') ? substr($main, 4) : "www.{$main}";
        $extra = array_map(fn (string $name) => strtolower(trim($name)), explode(',', (string) config('gallery.trusted_hosts')));

        return array_values(array_filter([$main, $twin, ...$extra]));
    }
}
