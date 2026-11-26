<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Support\ConversionsFromPreview;
use App\Support\SmallThumbnails;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;

beforeEach(fn () => Storage::fake('gallery'));

it('stores the original, generates conversions and sets the album cover', function () {
    $album = Album::factory()->create();

    $photo = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600);
    $media = $photo->getFirstMedia(Photo::COLLECTION);

    expect($photo->title)->toBe('Holiday Beach')
        ->and([$photo->width, $photo->height])->toBe([800, 600])
        ->and($media->disk)->toBe('gallery')
        ->and($media->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($album->fresh()->cover_photo_id)->toBe($photo->id);
});

it('makes thumbnails and previews without the image optimizers, at a fixed quality', function (string $conversion) {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    $media = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);

    $manipulations = ConversionCollection::createForMedia($media)->getByName($conversion)->getManipulations();

    // The optimizers only run where binaries such as cwebp are installed, which tests cannot rely on, so this checks
    // the registered conversion: the optimizers re-encoded the finished WebP and made it up to twice as large.
    expect($manipulations->getManipulationArgument('optimize'))->toBeNull();
    expect($manipulations->getFirstManipulationArgument('quality'))->toBe(Photo::CONVERSION_QUALITY);
})->with(['preview', 'thumb', 'small']);

it('makes the three sizes of a new photo, each fitting its longest side', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    $media = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);

    $sizes = collect(['preview', 'thumb', 'small'])->mapWithKeys(fn (string $conversion) => [
        $conversion => getimagesizefromstring(Storage::disk('gallery')->get($media->getPathRelativeToRoot($conversion)))[0],
    ]);

    // The 800 x 600 test photo is never enlarged: the preview keeps 800.
    expect($sizes->all())->toBe(['preview' => 800, 'thumb' => 720, 'small' => 360]);
    // The preview comes first, so the smaller sizes can start from it (ConversionsFromPreview).
    expect(ConversionCollection::createForMedia($media)->map->getName()->all())->toBe(['preview', 'thumb', 'small']);
});

it('starts the smaller sizes from the preview made in the same run, not from the original', function (string $conversion, bool $previewInRun, string $expected) {
    $folder = sys_get_temp_dir().'/og-run-'.Str::random(8);
    mkdir($folder);
    touch($original = $folder.'/original.jpg');
    if ($previewInRun) {
        touch($folder.'/Holiday-Beach-preview.webp');
    }

    $source = (new ConversionsFromPreview)->convert($original, Conversion::create($conversion));

    expect(basename($source))->toBe($expected);
    (new Filesystem)->deleteDirectory($folder);
})->with([
    'thumb after the preview' => ['thumb', true, 'Holiday-Beach-preview.webp'],
    'small after the preview' => ['small', true, 'Holiday-Beach-preview.webp'],
    'the preview itself' => ['preview', true, 'original.jpg'],
    'thumb made on its own' => ['thumb', false, 'original.jpg'],
]);

it('makes the small size of older photos from their thumbnail in the background', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    $media = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);
    Storage::disk('gallery')->delete($media->getPathRelativeToRoot('small'));
    $media->markAsConversionNotGenerated('small');

    $made = SmallThumbnails::makeMissing();

    expect($made)->toBe(1);
    expect($media->fresh()->hasGeneratedConversion('small'))->toBeTrue();
    expect(getimagesizefromstring(Storage::disk('gallery')->get($media->getPathRelativeToRoot('small')))[0])->toBe(360);
});

it('stores the size of photos taken upright as they are seen, and keeps their files upright', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();

    $photo = app(AddPhotoToAlbum::class)->handle($album, turnedJpeg(800, 600, 6), 'Upright.jpg');

    $media = $photo->getFirstMedia(Photo::COLLECTION);
    $size = fn (string $conversion) => array_slice(getimagesizefromstring(Storage::disk('gallery')->get($media->getPathRelativeToRoot($conversion))), 0, 2);
    expect([$photo->width, $photo->height])->toBe([600, 800]);
    expect([$size('preview'), $size('thumb')])->toBe([[600, 800], [540, 720]]);
});
