<?php

namespace App\Http\Controllers;

use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the uploaded logo / favicon from private storage, so no
 * `storage:link` is needed on shared hosting.
 */
class BrandingController extends Controller
{
    public function __invoke(string $asset): StreamedResponse
    {
        $path = SiteSettings::assetPath($asset);
        abort_unless($path, 404);

        $response = Storage::disk(SiteSettings::DISK)->response($path);

        // Uploaded SVGs must never execute scripts when opened directly.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }
}
