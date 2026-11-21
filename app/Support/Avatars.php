<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

/**
 * Profile pictures. Uploads are re-encoded on the server (square WebP, no
 * metadata), kept on the private `local` disk and served by AvatarController
 * to logged-in users only.
 */
class Avatars
{
    public const DISK = 'local';

    public const DIRECTORY = 'avatars';

    public const SIZE = 256;

    /** Accepted upload types. SVG is excluded: it could carry scripts. */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const MAX_KB = 5120;

    /**
     * Crops the image at $sourcePath to a square, re-encodes it and stores it.
     *
     * @return string The stored path on the avatars disk.
     */
    public static function store(string $sourcePath): string
    {
        $path = self::DIRECTORY.'/'.Str::random(40).'.webp';
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::DIRECTORY);

        Image::useImageDriver(config('media-library.image_driver', 'gd'))
            ->loadFile($sourcePath)
            ->fit(Fit::Crop, self::SIZE, self::SIZE)
            ->format('webp')
            ->save($disk->path($path));

        return $path;
    }

    public static function delete(?string $path): void
    {
        if (filled($path) && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** URL of the user's picture, or null to fall back to initials. */
    public static function url(User $user): ?string
    {
        if (blank($user->avatar_path)) {
            return null;
        }

        // The file name changes with every upload, so it doubles as a cache buster.
        return route('avatars.show', ['user' => $user, 'v' => substr(md5($user->avatar_path), 0, 8)]);
    }

    /** Up to two initials, e.g. "Ada Lovelace" → "AL". */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', $name)) ?: '') ?: [];
        $words = array_values(array_filter($words));

        $initials = match (count($words)) {
            0 => '?',
            1 => mb_substr($words[0], 0, 1),
            default => mb_substr($words[0], 0, 1).mb_substr($words[count($words) - 1], 0, 1),
        };

        return mb_strtoupper($initials);
    }
}
