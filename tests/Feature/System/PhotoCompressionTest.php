<?php

use App\Enums\BackgroundJobMode;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Support\BackgroundJobs;
use App\Support\PhotoCompression;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('gallery');
    config(['media-library.image_driver' => 'gd']);
});

it('leaves uploads as they are while compression is off', function () {
    $path = noisyImageFile(1600, 1200);
    $original = file_get_contents($path);

    $photo = photoFromFile(Album::factory()->create(), $path);

    expect(storedOriginal($photo))->toBe($original)
        ->and([$photo->width, $photo->height])->toBe([1600, 1200])
        ->and($photo->original()->getCustomProperty(PhotoCompression::PROPERTY))->toBeNull();
});

it('scales a large photo down to the longest side, but never enlarges a small one', function () {
    useCompression(maxPx: 800);
    $album = Album::factory()->create();
    $path = noisyImageFile(1600, 1200);
    $before = filesize($path);

    $large = photoFromFile($album, $path);
    $media = $large->original();

    expect(getimagesizefromstring(storedOriginal($large))[0])->toBe(800)
        ->and(getimagesizefromstring(storedOriginal($large))[1])->toBe(600)
        ->and([$large->width, $large->height])->toBe([800, 600])
        ->and($media->size)->toBeLessThan($before)
        ->and($media->size)->toBe(strlen(storedOriginal($large)))
        ->and($media->getCustomProperty(PhotoCompression::PROPERTY))->toBe(['max' => 800, 'quality' => null]);

    $smallPath = noisyImageFile(700, 500);
    $small = photoFromFile($album, $smallPath);

    expect(storedOriginal($small))->toBe(file_get_contents($smallPath))
        ->and([$small->width, $small->height])->toBe([700, 500]);
});

it('saves a photo at a lower quality without changing its size', function () {
    useCompression(quality: 60);
    $path = noisyImageFile(1600, 1200);
    $before = filesize($path);

    $photo = photoFromFile(Album::factory()->create(), $path);

    expect(strlen(storedOriginal($photo)))->toBeLessThan($before)
        ->and(array_slice(getimagesizefromstring(storedOriginal($photo)), 0, 2))->toBe([1600, 1200])
        ->and([$photo->width, $photo->height])->toBe([1600, 1200])
        ->and($photo->original()->getCustomProperty(PhotoCompression::PROPERTY))->toBe(['max' => null, 'quality' => 60]);
});

it('scales down and lowers the quality together, the file getting smaller than with scaling alone', function () {
    $path = noisyImageFile(1600, 1200);
    $album = Album::factory()->create();

    useCompression(maxPx: 800);
    $scaledOnly = strlen(storedOriginal(photoFromFile($album, $path)));

    useCompression(maxPx: 800, quality: 40);
    $both = photoFromFile($album, $path);

    expect(array_slice(getimagesizefromstring(storedOriginal($both)), 0, 2))->toBe([800, 600])
        ->and(strlen(storedOriginal($both)))->toBeLessThan($scaledOnly)
        ->and($both->original()->getCustomProperty(PhotoCompression::PROPERTY))->toBe(['max' => 800, 'quality' => 40]);
});

it('keeps the original when the compressed file would not be smaller', function () {
    useCompression(quality: 100);
    $path = noisyImageFile(800, 600, quality: 10);
    $original = file_get_contents($path);

    $photo = photoFromFile(Album::factory()->create(), $path);

    expect(storedOriginal($photo))->toBe($original)
        ->and([$photo->width, $photo->height])->toBe([800, 600]);
});

it('keeps the orientation and the camera data of a photo that is stored turned', function () {
    useCompression(maxPx: 800, quality: 60);
    $path = cameraJpegFile(1600, 1200, orientation: 6);
    $before = filesize($path);

    $photo = photoFromFile(Album::factory()->create(), $path);
    $stored = storedOriginal($photo);
    $file = tempnam(sys_get_temp_dir(), 'og');
    file_put_contents($file, $stored);
    $exif = exif_read_data($file);

    // Stored 800 x 600 and shown a quarter turn, so 600 wide and 800 high.
    expect(strlen($stored))->toBeLessThan($before)
        ->and(array_slice(getimagesizefromstring($stored), 0, 2))->toBe([800, 600])
        ->and([$photo->width, $photo->height])->toBe([600, 800])
        ->and($exif['Orientation'])->toBe(6)
        ->and($exif['Make'])->toBe('Acme')
        ->and($exif['DateTimeOriginal'])->toBe('2024:05:17 10:30:00')
        ->and($exif['GPSLatitudeRef'])->toBe('N')
        ->and($exif['GPSLatitude'])->toBe(['52/1', '31/1', '1234/100'])
        ->and($photo->exif['Make'])->toBe('Acme')
        ->and($photo->exif['Orientation'])->toBe(6)
        ->and($photo->taken_at->format('Y-m-d H:i:s'))->toBe('2024-05-17 10:30:00');
});

it('scales PNG files down but never saves them again at a quality', function () {
    $path = noisyImageFile(1600, 1200, 'png');
    $album = Album::factory()->create();

    useCompression(quality: 40);
    $untouched = photoFromFile($album, $path);

    expect(storedOriginal($untouched))->toBe(file_get_contents($path));

    useCompression(maxPx: 800, quality: 40);
    $scaled = photoFromFile($album, $path);

    expect(getimagesizefromstring(storedOriginal($scaled))['mime'])->toBe('image/png')
        ->and([$scaled->width, $scaled->height])->toBe([800, 600])
        ->and(strlen(storedOriginal($scaled)))->toBeLessThan(filesize($path));
});

it('keeps the text chunks of a scaled PNG', function () {
    useCompression(maxPx: 800);
    $png = (string) file_get_contents(noisyImageFile(1600, 1200, 'png'));
    $path = tempnam(sys_get_temp_dir(), 'og').'.png';
    file_put_contents($path, substr($png, 0, 33).pngChunk('tEXt', "Author\0Ada").substr($png, 33));

    $stored = storedOriginal(photoFromFile(Album::factory()->create(), $path));

    expect($stored)->toContain("Author\0Ada")
        ->and(pngChecksumsAreValid($stored))->toBeTrue()
        ->and(getimagesizefromstring($stored)[0])->toBe(800);
});

it('keeps the metadata of a compressed WebP', function () {
    useCompression(maxPx: 800, quality: 60);
    $path = cameraWebpFile(1600, 1200);

    $photo = photoFromFile(Album::factory()->create(), $path);
    $stored = storedOriginal($photo);

    expect(strlen($stored))->toBeLessThan(filesize($path))
        ->and(array_slice(getimagesizefromstring($stored), 0, 2))->toBe([800, 600])
        ->and([$photo->width, $photo->height])->toBe([800, 600])
        ->and($stored)->toContain('EXIF')
        ->and($stored)->toContain(cameraExif())
        ->and(imagecreatefromstring($stored))->not->toBeFalse();
});

it('does not touch GIF files', function () {
    useCompression(maxPx: 800, quality: 40);
    $path = noisyImageFile(1600, 1200, 'gif');

    $photo = photoFromFile(Album::factory()->create(), $path);

    expect(storedOriginal($photo))->toBe(file_get_contents($path))
        ->and([$photo->width, $photo->height])->toBe([1600, 1200]);
});

describe('photos stored before', function () {
    /** A photo uploaded while compression was off, with compression turned on afterwards. */
    function storedBeforeCompression(int $width = 1600, int $height = 1200): Photo
    {
        return photoFromFile(Album::factory()->create(), noisyImageFile($width, $height));
    }

    it('are compressed in the background after an admin asks for it, until all were looked at', function () {
        $large = storedBeforeCompression();
        $small = storedBeforeCompression(700, 500);
        $smallBytes = storedOriginal($small);
        $before = $large->original()->size;

        useCompression(maxPx: 800, quality: 70);

        expect(PhotoCompression::hasWork())->toBeFalse()
            ->and(PhotoCompression::compressExisting())->toBe(0)
            ->and(strlen(storedOriginal($large)))->toBe($before);

        PhotoCompression::startExisting();

        expect(PhotoCompression::hasWork())->toBeTrue()
            ->and(PhotoCompression::remaining())->toBe(2);

        // The small photo is only saved again at the new quality; the large one is scaled as well.
        expect(PhotoCompression::compressExisting())->toBe(2);

        $media = $large->original()->refresh();

        expect($large->fresh()->only(['width', 'height']))->toBe(['width' => 800, 'height' => 600])
            ->and($media->size)->toBeLessThan($before)
            ->and($media->size)->toBe(strlen(storedOriginal($large)))
            ->and(getimagesizefromstring(storedOriginal($large))[0])->toBe(800)
            ->and($media->getCustomProperty(PhotoCompression::PROPERTY))->toBe(['max' => 800, 'quality' => 70])
            ->and($small->fresh()->only(['width', 'height']))->toBe(['width' => 700, 'height' => 500])
            ->and(strlen(storedOriginal($small)))->toBeLessThan(strlen($smallBytes))
            ->and(Setting::get(PhotoCompression::EXISTING_CURSOR))->toBeNull()
            ->and(PhotoCompression::hasWork())->toBeFalse()
            ->and(PhotoCompression::remaining())->toBeNull();
    });

    it('keep their stored size and orientation when they are compressed', function () {
        $photo = photoFromFile(Album::factory()->create(), cameraJpegFile(1600, 1200, orientation: 6));
        useCompression(maxPx: 800);
        PhotoCompression::startExisting();

        PhotoCompression::compressExisting();

        expect($photo->fresh()->only(['width', 'height']))->toBe(['width' => 600, 'height' => 800])
            ->and(storedOriginal($photo))->toContain('Acme');
    });

    it('are left alone, and the run ends, when compression is off', function () {
        $photo = storedBeforeCompression();
        $bytes = storedOriginal($photo);
        useCompression(maxPx: 800);
        PhotoCompression::startExisting();
        Setting::put(SiteSettings::COMPRESSION_ENABLED, false);

        expect(PhotoCompression::compressExisting())->toBe(0)
            ->and(storedOriginal($photo))->toBe($bytes)
            ->and(Setting::get(PhotoCompression::EXISTING_CURSOR))->toBeNull();
    });

    it('are skipped when they were compressed with the same settings, and again when the quality is lowered', function () {
        useCompression(quality: 60);
        $photo = storedBeforeCompression();
        $bytes = storedOriginal($photo);

        PhotoCompression::startExisting();

        expect(PhotoCompression::compressExisting())->toBe(0)
            ->and(storedOriginal($photo))->toBe($bytes)
            ->and(Setting::get(PhotoCompression::EXISTING_CURSOR))->toBeNull();

        useCompression(quality: 30);
        PhotoCompression::startExisting();

        expect(PhotoCompression::compressExisting())->toBe(1)
            ->and(strlen(storedOriginal($photo)))->toBeLessThan(strlen($bytes))
            ->and($photo->original()->refresh()->getCustomProperty(PhotoCompression::PROPERTY))->toBe(['max' => null, 'quality' => 30]);
    });

    it('make the background jobs run soon again while they are being compressed', function () {
        useCompression(quality: 60);
        chooseBackgroundJobs(BackgroundJobMode::Ajax);
        $this->freezeTime();
        BackgroundJobs::run(BackgroundJobMode::Ajax);
        $this->travel(BackgroundJobs::AJAX_QUEUE_SECONDS + 1)->seconds();

        expect(BackgroundJobs::ajaxDue())->toBeFalse();

        PhotoCompression::startExisting();

        expect(BackgroundJobs::ajaxDue())->toBeTrue();
    });
});
