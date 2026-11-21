<?php

namespace App\Http\Controllers;

use App\Actions\StorePhotoUploads;
use App\Http\Requests\StorePhotoChunkRequest;
use App\Support\PhotoUploads;
use finfo;
use Illuminate\Http\JsonResponse;

/**
 * Receives photos in chunks (sent by the media picker's uploader, see
 * resources/views/filament/partials/photo-uploader-script.blade.php).
 *
 * Each request appends one chunk at `offset` to the unfinished upload
 * `upload` (a UUID chosen by the browser). A chunk at the wrong offset gets
 * 409 with the bytes received so far, so the browser resumes from there after
 * a network error. With the last chunk the file becomes a photo in the album
 * named by `target`, logged into one activity entry per `batch`. A repeated
 * last chunk gets the same photo back after a lost response, and finishes the
 * file after a failed attempt, instead of making a duplicate.
 */
class PhotoUploadController extends Controller
{
    public function __invoke(StorePhotoChunkRequest $request, StorePhotoUploads $store): JsonResponse
    {
        $user = $request->user();
        $part = PhotoUploads::partPath($user, $request->string('upload')->value());
        $finished = $part.'.done';

        if (is_file($finished)) {
            return response()->json(['done' => true, 'photo' => (int) file_get_contents($finished)]);
        }

        if ($request->integer('offset') === 0 && random_int(1, 50) === 1) {
            PhotoUploads::prune();
        }

        $size = $request->integer('size');
        $received = $this->append($part, $request->integer('offset'), $request->file('chunk')->getRealPath());

        if ($received === null) {
            clearstatcache(true, $part);
            $held = is_file($part) ? filesize($part) : 0;

            // The whole file is here, but the request with its last chunk failed: finish it now.
            return $held === $size
                ? $this->finish($request, $store, $part, $finished)
                : response()->json(['received' => $held], 409);
        }

        if ($received > $size) {
            @unlink($part);

            return response()->json(['message' => __('The file changed while it was uploaded. Please try again.')], 422);
        }

        if ($received < $size) {
            return response()->json(['received' => $received]);
        }

        return $this->finish($request, $store, $part, $finished);
    }

    /**
     * Appends $chunk to $part if $part holds exactly $offset bytes.
     *
     * @return int|null the new size, or null if $offset does not match
     */
    private function append(string $part, int $offset, string $chunk): ?int
    {
        $target = fopen($part, 'c+b');
        flock($target, LOCK_EX);

        try {
            if (fstat($target)['size'] !== $offset) {
                return null;
            }

            fseek($target, 0, SEEK_END);
            $source = fopen($chunk, 'rb');
            stream_copy_to_stream($source, $target);
            fclose($source);
            fflush($target);

            return fstat($target)['size'];
        } finally {
            flock($target, LOCK_UN);
            fclose($target);
        }
    }

    /**
     * Turns the complete file into a photo (one photo per request keeps time
     * and memory in bounds). A lock per upload keeps a retry from making a
     * second photo while the first attempt is still running; the lock file
     * is removed with the other leftovers (PhotoUploads::prune()).
     */
    private function finish(StorePhotoChunkRequest $request, StorePhotoUploads $store, string $part, string $finished): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $lock = fopen($part.'.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            clearstatcache();

            if (is_file($finished)) {
                return response()->json(['done' => true, 'photo' => (int) file_get_contents($finished)]);
            }

            if (! is_file($part)) {
                return response()->json(['received' => 0], 409);
            }

            $name = $request->string('name')->value();
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($part);

            if (! in_array($mime, config('gallery.accepted_mime_types'), true)) {
                @unlink($part);

                return response()->json(['message' => __('Not a supported image.')], 422);
            }

            $album = PhotoUploads::album($request->string('target')->value(), $request->user());
            $result = $store->handleFiles($album, [['path' => $part, 'name' => $name]], $request->string('batch')->value());
            $photo = $result['photos'][0] ?? null;

            if (! $photo) {
                return response()->json(['message' => $result['errors'][$name] ?? __('Not a supported image.')], 422);
            }

            file_put_contents($finished, (string) $photo->id);

            return response()->json(['done' => true, 'photo' => $photo->id]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
