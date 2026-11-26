<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Setting;
use App\Support\PhotoExif;
use App\Support\PhotoGridItems;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;

beforeEach(fn () => Storage::fake('gallery'));

it('gives the lightbox the camera details in a readable form', function () {
    $photo = photoWithCameraData([
        'Make' => 'Acme',
        'Model' => 'Pro 5',
        'LensModel' => '50mm F1.8',
        'FocalLength' => '50/1',
        'FNumber' => '28/10',
        'ExposureTime' => '1/250',
        'ISOSpeedRatings' => 400,
        'GPSLatitude' => ['52/1', '31/1', '0/1'],
        'GPSLongitude' => ['13/1', '24/1', '0/1'],
    ]);

    $info = PhotoGridItems::from([$photo])[0]['lightbox']['info'];

    expect($info)->toBe([
        ['label' => 'Make', 'value' => 'Acme'],
        ['label' => 'Camera', 'value' => 'Pro 5'],
        ['label' => 'Lens', 'value' => '50mm F1.8'],
        ['label' => 'Focal length', 'value' => '50 mm'],
        ['label' => 'Aperture', 'value' => 'f/2.8'],
        ['label' => 'Exposure', 'value' => '1/250 s'],
        ['label' => 'ISO', 'value' => '400'],
    ]);
});

it('never includes location data', function () {
    $photo = photoWithCameraData(['Make' => 'Acme', 'GPSLatitude' => ['52/1', '31/1', '0/1'], 'GPSLatitudeRef' => 'N']);

    $json = json_encode(PhotoGridItems::from([$photo])[0]['lightbox']);

    expect($json)->not->toContain('GPS')->not->toContain('52/1')
        ->and(PhotoExif::details($photo))->toBe([['label' => 'Make', 'value' => 'Acme']]);
});

it('gives an empty list for a photo without camera data', function () {
    expect(PhotoGridItems::from([photoWithCameraData([])])[0]['lightbox']['info'])->toBe([]);
});

it('shows exposures of a second or more in seconds', function () {
    expect(PhotoExif::value('ExposureTime', '2/1'))->toBe('2 s')
        ->and(PhotoExif::value('ExposureTime', '5/10'))->toBe('1/2 s');
});

it('leaves the information out while the setting is off', function () {
    Setting::put(SiteSettings::PHOTO_INFO_ENABLED, false);
    $photo = photoWithCameraData(['Make' => 'Acme']);

    expect(PhotoGridItems::from([$photo])[0]['lightbox']['info'])->toBeNull();
});

it('hands the slideshow and information settings to the viewer on the album page', function (bool $enabled) {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    galleryPhoto($album);
    Setting::put(SiteSettings::SLIDESHOW_SECONDS, 9);
    Setting::put(SiteSettings::SLIDESHOW_ENABLED, $enabled);
    Setting::put(SiteSettings::PHOTO_INFO_ENABLED, $enabled);

    $config = Js::from(['slideshow' => $enabled, 'seconds' => 9, 'info' => $enabled]);

    $this->get(route('albums.show', $album))->assertOk()->assertSee('configure('.$config.')', false);
})->with([true, false]);
