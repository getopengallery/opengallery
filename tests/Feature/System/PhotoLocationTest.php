<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Setting::put(SiteSettings::DOWNLOADS_ENABLED, true));

it('removes the GPS position from a JPEG and keeps everything else', function (bool $littleEndian) {
    $original = jpegWithPosition($littleEndian);
    $clean = withoutLocation($original);
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    file_put_contents($path, $clean);
    $exif = @exif_read_data($path);

    expect(strlen($clean))->toBe(strlen($original))
        ->and(str_contains($original, secretLatitudeBytes($littleEndian)))->toBeTrue()
        ->and(str_contains($clean, secretLatitudeBytes($littleEndian)))->toBeFalse()
        ->and($exif['Make'])->toBe('Acme')
        ->and($exif)->not->toHaveKey('GPSLatitude')
        ->and(imagecreatefromstring($clean))->not->toBeFalse();
})->with(['little endian' => true, 'big endian' => false]);

it('removes it from a PNG and keeps the file valid', function () {
    $clean = withoutLocation(pngWithChunk(pngChunk('eXIf', exifWithPosition())));

    expect(str_contains($clean, secretLatitudeBytes()))->toBeFalse()
        ->and(str_contains($clean, 'Acme'))->toBeTrue()
        ->and(pngChecksumsAreValid($clean))->toBeTrue()
        ->and(imagecreatefromstring($clean))->not->toBeFalse();
});

it('blanks compressed XMP in a PNG', function () {
    $xmp = "XML:com.adobe.xmp\0\1\0\0\0".gzcompress('<x:xmpmeta exif:GPSLatitude="52,31.0N"/>');

    $clean = withoutLocation(pngWithChunk(pngChunk('iTXt', $xmp)));

    expect(str_contains($clean, gzcompress('<x:xmpmeta exif:GPSLatitude="52,31.0N"/>')))->toBeFalse()
        ->and(pngChecksumsAreValid($clean))->toBeTrue();
});

it('removes it from a WebP', function () {
    $clean = withoutLocation(webpWithPosition());

    expect(str_contains($clean, secretLatitudeBytes()))->toBeFalse()
        ->and(str_contains($clean, 'Acme'))->toBeTrue()
        ->and(imagecreatefromstring($clean))->not->toBeFalse();
});

it('blanks GPS values in XMP, as attributes and as elements', function () {
    $xmp = '<x:xmpmeta exif:GPSLatitude="52,31.0N" tiff:Make="Acme"><exif:GPSLongitude>13,24.0E</exif:GPSLongitude></x:xmpmeta>';
    $segment = "\xFF\xE1".pack('n', strlen($xmp) + 31)."http://ns.adobe.com/xap/1.0/\0".$xmp;

    $clean = withoutLocation(jpegWithPosition(extraSegment: $segment));

    expect($clean)->not->toContain('52,31.0N')
        ->not->toContain('13,24.0E')
        ->toContain('tiff:Make="Acme"');
});

it('leaves a file without a position untouched', function () {
    $image = imageBytes('jpeg');

    expect(withoutLocation($image))->toBe($image);
});

it('never changes the stored file', function () {
    Storage::fake('gallery');
    $album = Album::factory()->create(['visibility' => Visibility::Public, 'allow_downloads' => true]);
    $media = photoWithPosition($album)->original();

    $this->get(route('media.show', $media))->assertOk()->streamedContent();

    expect(str_contains(Storage::disk('gallery')->get($media->getPathRelativeToRoot()), secretLatitudeBytes()))->toBeTrue();
});
