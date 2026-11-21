<?php

namespace App\Http\Controllers;

use App\Extensions\ThemeManager;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ThemeAssetController extends Controller
{
    /** An image uploaded in a theme's settings (private disk, "themes/{slug}/..."). */
    public function upload(string $path): StreamedResponse
    {
        abort_unless(str_starts_with($path, 'themes/') && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk(ThemeManager::UPLOAD_DISK)->exists($path), 404);

        $response = Storage::disk(ThemeManager::UPLOAD_DISK)->response($path);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }

    public function __invoke(ThemeManager $themes, string $theme, string $path): BinaryFileResponse
    {
        $file = $themes->assetPath($theme, $path);
        abort_unless($file, 404);

        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            default => mime_content_type($file) ?: 'application/octet-stream',
        };

        return response()->file($file, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
