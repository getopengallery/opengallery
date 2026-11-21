<?php

namespace App\Actions;

use App\Models\Album;
use App\Models\Photo;
use App\Support\ActivityLog;
use App\Support\PhotoCannotBeProcessed;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns uploaded files into photos in $album, logging the batch once.
 *
 * Chunked uploads (PhotoUploadController) arrive one photo per request; they
 * pass the browser's batch id so the whole batch still reads as one entry.
 */
class StorePhotoUploads
{
    use DetectsConcurrencyErrors;

    public function __construct(private AddPhotoToAlbum $add) {}

    /**
     * @param  list<string>  $paths  files on the local disk
     * @param  array<string, string>  $names  original file names by path
     * @return array{photos: list<Photo>, failed: list<string>, errors: array<string, string>}
     */
    public function handle(Album $album, array $paths, array $names = []): array
    {
        return $this->handleFiles($album, array_map(
            fn (string $path) => ['path' => Storage::disk('local')->path($path), 'name' => $names[$path] ?? basename($path)],
            array_values($paths),
        ));
    }

    /**
     * Same, for files given by absolute path. The files are moved into the
     * media library, or deleted when they cannot be added. A busy database
     * is not the file's fault: that error is passed on and the file stays,
     * so the upload can be tried again.
     *
     * @param  list<array{path: string, name: string}>  $files
     * @param  string|null  $batch  groups several calls into one activity entry
     * @return array{photos: list<Photo>, failed: list<string>, errors: array<string, string>}
     */
    public function handleFiles(Album $album, array $files, ?string $batch = null): array
    {
        $photos = [];
        $failed = [];
        $errors = [];

        foreach ($files as ['path' => $path, 'name' => $name]) {
            try {
                $photos[] = $this->add->handle($album, $path, $name, auth()->user());
            } catch (Throwable $e) {
                if ($this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                if (! $e instanceof PhotoCannotBeProcessed) {
                    report($e);
                }
                $failed[] = $name;
                $errors[$name] = $e instanceof PhotoCannotBeProcessed ? $e->getMessage() : __('Not a supported image.');
                @unlink($path);
            }
        }

        ActivityLog::photosUploaded($album, count($photos), count($failed), $batch);

        return ['photos' => $photos, 'failed' => $failed, 'errors' => $errors];
    }
}
