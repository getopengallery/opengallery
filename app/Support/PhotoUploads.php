<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Album;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Rules for uploading photos in pieces (chunks), so that neither PHP's
 * upload_max_filesize / post_max_size nor the request time limit caps how
 * large a photo or a batch may be:
 *
 * - where uploads may go ("album:{id}", "inbox", "page-images"),
 * - the size of one chunk (fits the PHP limits) and of one photo,
 * - where unfinished uploads are kept (local disk, `upload-chunks/{user}`),
 * - whether this PHP process has the memory to decode an image with GD.
 *
 * Used by the media picker (App\Livewire\MediaPicker) and
 * App\Http\Controllers\PhotoUploadController.
 */
class PhotoUploads
{
    /** Folder on the local disk for unfinished uploads. */
    public const CHUNK_DIRECTORY = 'upload-chunks';

    /** Unfinished uploads older than this are removed by prune(). */
    public const STALE_HOURS = 24;

    /** File extensions accepted for the mime types in gallery.accepted_mime_types. */
    private const EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg', 'jfif'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/heic' => ['heic', 'heif'],
        'image/avif' => ['avif'],
    ];

    /** Photo formats the gallery knows, with the names shown in Admin → Settings. */
    public const TYPES = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
        'image/gif' => 'GIF',
        'image/heic' => 'HEIC / HEIF',
        'image/avif' => 'AVIF',
    ];

    /** Format names as Imagick knows them. */
    private const IMAGICK_FORMATS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
        'image/gif' => 'GIF',
        'image/heic' => 'HEIC',
        'image/avif' => 'AVIF',
    ];

    /** Whether $user may upload to $target (without creating any album). */
    public static function allows(?string $target, ?User $user): bool
    {
        if ($target === null || $user === null) {
            return false;
        }

        $usesAdmin = $user->can(Permission::AccessAdmin->value) && $user->can(Permission::UploadPhotos->value);

        if (str_starts_with($target, 'album:')) {
            $album = Album::find((int) substr($target, 6));

            // A collaborator role is enough on its own, without either of the two permissions.
            return $album !== null
                && ($usesAdmin || $album->collaboratorRole($user) !== null)
                && $user->can('upload', $album);
        }

        return $usesAdmin && in_array($target, ['inbox', 'page-images'], true);
    }

    /**
     * Settings for the browser-side uploader ("ogPhotoUploader"), for a target
     * allows() accepts.
     *
     * @return array{url: string, target: string, chunkSize: int, maxSize: int, extensions: list<string>, multiple: bool, csrf: string, messages: array<string, string>}
     */
    public static function uploaderConfig(string $target, bool $multiple = true): array
    {
        return [
            'url' => route('uploads.photos'),
            'target' => $target,
            'chunkSize' => self::chunkBytes(),
            'maxSize' => self::maxBytes(),
            'extensions' => self::acceptedExtensions(),
            'multiple' => $multiple,
            'csrf' => csrf_token(),
            'messages' => self::uploaderMessages(),
        ];
    }

    /**
     * Texts of the uploader script, in the user's language; ":name" parts are
     * filled in by the script.
     *
     * @return array<string, string>
     */
    private static function uploaderMessages(): array
    {
        return [
            'unsupported' => __('This file type is not supported.'),
            'tooLarge' => __('Larger than the upload limit of :size.'),
            'empty' => __('The file is empty.'),
            'uploadedOne' => __(':count photo uploaded.'),
            'uploadedMany' => __(':count photos uploaded.'),
            'leftOne' => __(':count photo was not uploaded.'),
            'leftMany' => __(':count photos were not uploaded.'),
            'notResumed' => __('The upload could not be resumed.'),
            'sessionExpired' => __('Your session has expired. Reload the page and add the remaining photos again.'),
            'notAllowed' => __('You may no longer upload photos here.'),
            'notUploaded' => __('Not uploaded.'),
            'notAnImage' => __('Not a supported image.'),
            'chunkTooLarge' => __('The server refused the upload size. Ask the administrator to lower the upload chunk size.'),
            'failed' => __('The upload failed several times. Check your connection and try again.'),
            'status' => __('Uploading :current of :total · :sent of :size'),
            'leave' => __('Photos are still uploading. Leave this page and stop the upload?'),
        ];
    }

    /** The album uploads to $target go to; system albums are created on first use. */
    public static function album(?string $target, ?User $user): ?Album
    {
        if (! self::allows($target, $user)) {
            return null;
        }

        return match (true) {
            $target === 'inbox' => Album::inbox($user),
            $target === 'page-images' => Album::pageImages($user),
            default => Album::find((int) substr($target, 6)),
        };
    }

    /** Largest photo accepted, in bytes (gallery.max_upload_kb). */
    public static function maxBytes(): int
    {
        return (int) config('gallery.max_upload_kb') * 1024;
    }

    /**
     * Size of one chunk in bytes: gallery.upload_chunk_kb, or derived from
     * upload_max_filesize / post_max_size so every chunk gets through PHP.
     */
    public static function chunkBytes(): int
    {
        if ($configured = (int) config('gallery.upload_chunk_kb')) {
            return max(64, $configured) * 1024;
        }

        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            // Leave room for the other form fields and the multipart overhead.
            self::iniBytes((string) ini_get('post_max_size')) - 64 * 1024,
        ], fn (int $bytes) => $bytes > 0);

        // Large enough to keep the number of requests down, small enough for proxies.
        return max(256 * 1024, min([8 * 1024 * 1024, ...$limits]));
    }

    /**
     * Formats this server's image library (media-library.image_driver) can
     * decode, so thumbnails can be made. GD never reads HEIC.
     *
     * @return list<string> mime types
     */
    public static function processableTypes(): array
    {
        if (config('media-library.image_driver') === 'imagick') {
            if (! class_exists(\Imagick::class)) {
                return [];
            }

            return array_keys(array_filter(self::IMAGICK_FORMATS, fn (string $format) => \Imagick::queryFormats($format) !== []));
        }

        if (! function_exists('imagetypes')) {
            return [];
        }

        $flags = [
            'image/jpeg' => IMG_JPG,
            'image/png' => IMG_PNG,
            'image/webp' => IMG_WEBP,
            'image/gif' => IMG_GIF,
            'image/avif' => IMG_AVIF,
        ];

        return array_keys(array_filter($flags, fn (int $flag) => (imagetypes() & $flag) !== 0));
    }

    /** @return list<string> lower-case file extensions that may be uploaded */
    public static function acceptedExtensions(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (string $mime) => self::EXTENSIONS[$mime] ?? [],
            config('gallery.accepted_mime_types'),
        ))));
    }

    public static function hasAcceptedExtension(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::acceptedExtensions(), true);
    }

    /** Absolute path of the unfinished upload $uploadId of $user (the directory is created). */
    public static function partPath(User $user, string $uploadId): string
    {
        $disk = Storage::disk('local');
        $directory = self::CHUNK_DIRECTORY.'/'.$user->getKey();
        $disk->makeDirectory($directory);

        return $disk->path($directory.'/'.$uploadId.'.part');
    }

    /** Removes unfinished uploads (and finished-upload markers) older than STALE_HOURS. */
    public static function prune(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours(self::STALE_HOURS)->getTimestamp();
        $removed = 0;

        foreach ($disk->allFiles(self::CHUNK_DIRECTORY) as $file) {
            if ($disk->lastModified($file) < $cutoff && $disk->delete($file)) {
                $removed++;
            }
        }

        clearstatcache();

        // Only long-empty folders: a running upload may have made this one a moment ago.
        foreach ($disk->directories(self::CHUNK_DIRECTORY) as $directory) {
            if ($disk->allFiles($directory) === [] && filemtime($disk->path($directory)) < $cutoff) {
                $disk->deleteDirectory($directory);
            }
        }

        return $removed;
    }

    /**
     * Makes sure this process can decode a $width x $height image with GD
     * (which holds every pixel in memory), raising memory_limit if allowed.
     * A fatal out-of-memory error cannot be caught and would leave half a
     * photo behind, so too-large images are refused up front instead.
     *
     * @throws PhotoCannotBeProcessed
     */
    public static function ensureMemoryFor(int $width, int $height): void
    {
        if (config('media-library.image_driver') !== 'gd' || $width <= 0 || $height <= 0) {
            return;
        }

        // ~4 bytes per pixel for the decoded image, plus working copies and headroom.
        $needed = memory_get_usage() + (int) ($width * $height * 5.5) + 32 * 1024 * 1024;
        $limit = self::iniBytes((string) ini_get('memory_limit'));

        if ($limit <= 0 || $limit >= $needed) {
            return;
        }

        @ini_set('memory_limit', (string) $needed);
        $raised = self::iniBytes((string) ini_get('memory_limit'));

        if ($raised > 0 && $raised < $needed) {
            throw new PhotoCannotBeProcessed(__('The image is too large for this server (:width x :height pixels needs about :mb MB of PHP memory).', [
                'width' => $width, 'height' => $height, 'mb' => (int) ceil($needed / 1024 / 1024),
            ]));
        }
    }

    /** "8M" / "1G" / "512K" / "-1" in php.ini notation to bytes (<= 0: unlimited). */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
