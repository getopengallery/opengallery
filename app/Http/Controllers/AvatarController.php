<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Avatars;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves profile pictures from private storage to logged-in users.
 */
class AvatarController extends Controller
{
    public function __invoke(User $user): StreamedResponse
    {
        $path = $user->avatar_path;
        abort_unless(filled($path) && Storage::disk(Avatars::DISK)->exists($path), 404);

        $response = Storage::disk(Avatars::DISK)->response($path, headers: ['Content-Type' => 'image/webp']);
        $response->headers->set('Content-Security-Policy', "default-src 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');

        return $response;
    }
}
