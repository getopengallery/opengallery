<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Support\Documentation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves screenshots from `/docs/assets` to users of the admin panel, where
 * the documentation is rendered.
 */
class DocumentationAssetController extends Controller
{
    public function __invoke(Request $request, Documentation $docs, string $path): BinaryFileResponse
    {
        abort_unless($request->user()?->can(Permission::AccessAdmin->value), 403);

        $file = $docs->assetPath($path);
        abort_unless($file, 404);

        return response()->file($file, [
            'Content-Type' => Documentation::ASSET_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))],
            'Content-Security-Policy' => "default-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
