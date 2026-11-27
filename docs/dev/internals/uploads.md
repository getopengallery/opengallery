---
order: 20
---

# Photo uploads

Photos are uploaded in chunks, so large photos and large batches work on shared hosting with small PHP upload limits, without a queue worker.

## Flow

1. The media picker (`App\Livewire\MediaPicker`) renders a drop zone when `App\Support\PhotoUploads::allows()` lets the person upload to the picker's `uploadTo` target. Its settings come from `PhotoUploads::uploaderConfig()`. The targets are `album:{id}`, `inbox` and `page-images` (see [Media library and photo picker](media-library.md#uploads-in-the-picker)).
2. The Alpine component `ogPhotoUploader` (`resources/views/filament/partials/photo-uploader-script.blade.php`, added inline to every admin page through the `panels::scripts.before` render hook, with `data-navigate-once`) queues the dropped files and folders. It uploads two files at a time, chunk by chunk. Admin pages change without a reload ([SPA mode](admin-ui.md#page-changes-without-a-reload)), which `beforeunload` does not catch: while files are uploading, the component listens for `livewire:navigate` and asks "Photos are still uploading. Leave this page and stop the upload?" before leaving. A reload or a link out of the admin still triggers the browser's own prompt.
3. Each chunk is a `POST` to `route('uploads.photos')` (`App\Http\Controllers\PhotoUploadController`, validated by `App\Http\Requests\StorePhotoChunkRequest`). Fields: `upload` (UUID per file), `batch` (UUID per drop), `target`, `name`, `size`, `offset` and `chunk`.
4. The controller appends the chunk to `storage/app/private/upload-chunks/{user}/{upload}.part` if the file holds exactly `offset` bytes. Otherwise it answers `409` with `{"received": n}`, and the browser resumes from there. Failed chunks are retried with growing pauses.
5. With the last chunk, the controller checks the file's real mime type and turns it into a photo through `App\Actions\StorePhotoUploads::handleFiles()`. It answers `{"done": true, "photo": id}` and leaves a `.done` marker, so a repeated last chunk returns the same photo instead of a duplicate. If that request failed, the complete file is still there and the repeated last chunk finishes it. A lock file per upload keeps a retry from running next to the first attempt.
6. Every few seconds the browser hands the finished ids to `MediaPicker::addUploaded()`, which keeps only the person's own visible photos and returns them without redrawing anything (`#[Renderless]`). If the call fails, the ids are kept and sent again.
7. When the run is over, the browser calls `MediaPicker::showUploaded()` once, which redraws the grid with the new photos, and only then adds them to the grid's selection. Selecting earlier would move the grid, because Filament's "selected" bar appears above it. The upload box keeps one height while waiting and uploading for the same reason. Never reset the table here, because that would clear the selection. The page behind the picker shows the new photos when the picker closes.

One photo is processed per request, so time and memory per request stay bounded. All photos of one `batch` are merged into a single "uploaded N photos" activity entry (`ActivityLog::photosUploaded()` with a batch id).

## Making a photo

`App\Actions\AddPhotoToAlbum` must not make the conversions inside a database transaction: they take seconds, and on SQLite an open write transaction makes every other request wait. It works in three steps:

1. Create the photo as trashed, so no grid shows it without its images.
2. Store the original and make the conversions, outside any transaction. The source file stays until the photo is complete.
3. In one short transaction, restore the photo and add it to the album. The write comes first, because on SQLite a transaction that reads first cannot wait for the write lock (PHP 8.3 ignores `transaction_mode`).

If a step fails, the photo is removed again. When the database is busy (`DetectsConcurrencyErrors`), `StorePhotoUploads` passes the error on and keeps the file, so the browser's retry can finish it. Other errors discard the file and are shown to the uploader. A PHP timeout or out-of-memory error skips the normal error handling, so a shutdown function removes the unfinished photo. Only a process killed from outside leaves it behind, in its uploader's trash.

## Photo compression

`App\Support\PhotoCompression` shrinks photos as set in Admin → Settings → Photos (`SiteSettings::compressionMaxPx()` and `compressionQuality()`, each `null` while its part or compression is off; `PhotoCompression::isActive()` is true when either is set). `compress($path)` uses GD or Imagick, as chosen by `media-library.image_driver`, and only writes JPEG, WebP and PNG: PNG is lossless and only scaled down, GIF, HEIC, AVIF and animated WebP are left alone. A result that is not smaller is discarded and the original stays. Pixels keep their stored orientation. Imagick keeps the metadata itself; with GD the EXIF, XMP, ICC and IPTC segments or chunks are copied from the original, so `PhotoExif` and "remove location from downloads" keep working.

- New uploads: `AddPhotoToAlbum` calls it after reading the EXIF and the memory check, before `addMedia()`, and stores the media custom property `compression` (`['max' => ?int, 'quality' => ?int]`, the settings used).
- Existing photos: `startExisting()` sets the cursor setting `uploads.compression_existing` to 0 (the "Compress existing photos" action). The background job `compress-existing` (`BackgroundJobs::tasks()`, every pass) calls `compressExisting()`, which works through the media ids in batches of `BATCH` for up to `SECONDS` and clears the cursor when done or when compression was turned off. `hasWork()` makes AJAX passes start every minute (`BackgroundJobs::ajaxDue()`), and `remaining()` feeds the count on the settings page. Before a file is replaced, its size is kept once as the media property `original_size` ([File sizes](media-library.md#file-sizes)). `wouldChange()` skips photos whose stored `compression` property is already as strict as the current settings; a lower quality compresses them again.

## Limits and settings

| Setting | Default | Meaning |
| --- | --- | --- |
| `gallery.max_upload_kb` | 51200 | Largest single photo. Set in Admin → Settings (`SiteSettings::UPLOAD_MAX_MB`, in MB); `GALLERY_MAX_UPLOAD_KB` is only the initial default. `SiteSettings::apply()` sets Media Library's `max_file_size` to match. |
| `gallery.upload_chunk_kb` (`GALLERY_UPLOAD_CHUNK_KB`) | empty | Chunk size. Empty: the smaller of `upload_max_filesize`, `post_max_size` minus 64 KB, and 8 MB (at least 256 KB). |
| `gallery.accepted_mime_types` | JPEG, PNG, WebP, GIF, HEIC, AVIF | Set in Admin → Settings (`SiteSettings::UPLOAD_TYPES`, keys of `PhotoUploads::TYPES`). Only types in `PhotoUploads::processableTypes()` (what GD or Imagick can decode) can be chosen. Checked against the file name first and the file content at the end. |

With the GD driver, `PhotoUploads::ensureMemoryFor()` estimates the memory needed to decode the image (about 5.5 bytes per pixel plus headroom). It raises `memory_limit` if the host allows it. Otherwise it throws `App\Support\PhotoCannotBeProcessed`, whose message is shown to the uploader. This happens before anything is stored, because a fatal out-of-memory error would leave half a photo behind.

## Housekeeping

Unfinished uploads, `.done` markers, lock files and upload folders that have been empty for 24 hours are removed by `php artisan gallery:prune-uploads`. That command is scheduled daily in `routes/console.php`. For hosts without cron, `PhotoUploadController` also runs `PhotoUploads::prune()` at the start of about one in 50 uploads (first chunk, `random_int(1, 50)`).

## Other uploads

Avatars, branding and theme images still use Filament's `FileUpload` with Livewire temporary uploads. They are small, single files. For photos, always use the media picker (see [Media library and photo picker](media-library.md)) and never build a second photo upload path.
