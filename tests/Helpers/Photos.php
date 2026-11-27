<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use App\Support\PhotoGridItems;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** A real photo (a generated JPEG with its media files) uploaded by $uploader into $album. */
function galleryPhoto(Album $album, ?User $uploader = null, string $name = 'Photo.jpg', int $width = 240, int $height = 160): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    imagejpeg(imagecreatetruecolor($width, $height), $path);

    return app(AddPhotoToAlbum::class)->handle($album, $path, $name, $uploader);
}

/**
 * A real photo in $album on a freshly faked gallery disk, with $attributes applied.
 *
 * @param  array<string, mixed>  $attributes
 */
function photoOnFreshDisk(Album $album, array $attributes = []): Photo
{
    Storage::fake('gallery');

    return tap(galleryPhoto($album, name: 'photo.jpg', width: 160, height: 120))->update($attributes);
}

/**
 * A real photo in a new public album, with $exif stored as its camera data.
 *
 * @param  array<string, mixed>  $exif
 */
function photoWithCameraData(array $exif): Photo
{
    return tap(galleryPhoto(Album::factory()->visibility(Visibility::Public)->create()))->update(['exif' => $exif]);
}

/** A real photo whose uploaded file carries EXIF camera and GPS data. */
function photoWithPosition(Album $album, ?User $uploader = null): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    file_put_contents($path, jpegWithPosition());

    return app(AddPhotoToAlbum::class)->handle($album, $path, 'Lake.jpg', $uploader);
}

/** The URL of the thumbnail conversion of $photo. */
function photoThumbUrl(Photo $photo): string
{
    return route('media.show', [$photo->original(), 'thumb']);
}

/** A photo in a public album that allows downloads. */
function downloadablePhotoInPublicAlbum(): Photo
{
    return galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(['allow_downloads' => true]), null, 'Beach.jpg');
}

/**
 * The lightbox item of $photo as listed in its album (where the album decides on downloads).
 *
 * @return array<string, mixed>
 */
function downloadLightboxItem(Photo $photo): array
{
    return PhotoGridItems::from($photo->albums()->first()->photos()->get())[0]['lightbox'];
}

/**
 * Uploads a generated JPEG through the chunked photo upload endpoint, as the
 * media picker does, and returns the final response.
 *
 * @param  array<string, mixed>  $overrides  request fields to change
 */
function uploadPhotoInChunks(string $target, string $name = 'photo.jpg', ?string $batch = null, int $chunkSize = 2048, array $overrides = []): TestResponse
{
    $image = imagecreatetruecolor(160, 120);
    imagefilledrectangle($image, 0, 0, 80, 60, imagecolorallocate($image, 200, 40, 40));
    ob_start();
    imagejpeg($image, null, 95);
    $bytes = ob_get_clean();

    $upload = (string) Str::uuid();
    $batch ??= (string) Str::uuid();

    foreach (str_split($bytes, $chunkSize) as $index => $chunk) {
        $response = test()->postJson(route('uploads.photos'), [
            'upload' => $upload,
            'batch' => $batch,
            'target' => $target,
            'name' => $name,
            'size' => strlen($bytes),
            'offset' => $index * $chunkSize,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $chunk),
            ...$overrides,
        ]);

        if (! $response->isOk()) {
            return $response;
        }
    }

    return $response;
}

/**
 * Posts one chunk of a photo upload to the upload endpoint; $fields override the request.
 *
 * @param  array<string, mixed>  $fields
 */
function postChunk(array $fields, string $content): TestResponse
{
    return test()->postJson(route('uploads.photos'), [
        'upload' => (string) Str::uuid(),
        'batch' => (string) Str::uuid(),
        'target' => 'inbox',
        'name' => 'photo.jpg',
        'size' => strlen($content),
        'offset' => 0,
        ...$fields,
        'chunk' => UploadedFile::fake()->createWithContent('chunk', $content),
    ]);
}

/** A real photo uploaded by $uploader into $album from the image file at $path (which compression may replace). */
function photoFromFile(Album $album, string $path, ?User $uploader = null): Photo
{
    // The upload action removes the file it is given.
    $upload = tempnam(sys_get_temp_dir(), 'og').'.'.pathinfo($path, PATHINFO_EXTENSION);
    copy($path, $upload);

    return app(AddPhotoToAlbum::class)->handle($album, $upload, 'Upload.'.pathinfo($path, PATHINFO_EXTENSION), $uploader);
}

/** The bytes of the stored original of $photo. */
function storedOriginal(Photo $photo): string
{
    $media = $photo->original();

    return Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
}
