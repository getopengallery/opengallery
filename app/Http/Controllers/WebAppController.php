<?php

namespace App\Http\Controllers;

use App\Support\SiteSettings;
use App\Support\WebApp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The site as an installable app: web app manifest, app icons and the page
 * the service worker shows while there is no connection (see App\Support\WebApp).
 */
class WebAppController extends Controller
{
    public function manifest(): JsonResponse
    {
        abort_unless(WebApp::enabled(), 404);

        return response()
            ->json(WebApp::manifest(), options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * The icon made from the uploaded app icon or favicon, in one of
     * WebApp::ICON_SIZES. An upload that cannot be read gets the built-in mark,
     * so home screens never fall back to a letter.
     */
    public function icon(int $size): StreamedResponse|BinaryFileResponse
    {
        abort_unless(WebApp::iconSource(), 404);

        $path = WebApp::iconPath($size);

        if (! $path) {
            return response()->file(WebApp::builtInIconPath($size), ['Cache-Control' => 'public, max-age=3600']);
        }

        $response = Storage::disk(SiteSettings::DISK)->response($path, headers: ['Content-Type' => 'image/png']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }

    public function offline(): View
    {
        return view('offline');
    }
}
