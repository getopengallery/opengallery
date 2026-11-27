<?php

use App\Models\Album;
use App\Models\Setting;
use App\Support\BackgroundJobs;
use App\Support\MediaSizes;
use App\Support\PhotoCompression;
use App\Support\Statistics\LibraryStats;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('gallery');
    config(['media-library.image_driver' => 'gd']);
});

describe('the size before compression', function () {
    it('is kept when an upload is compressed, and not recorded without compression', function () {
        $path = noisyImageFile(1600, 1200);
        $uploaded = strlen(file_get_contents($path));

        $plain = photoFromFile(Album::factory()->create(), $path);
        useCompression(maxPx: 800);
        $compressed = photoFromFile(Album::factory()->create(), $path);

        $media = $compressed->original()->refresh();
        expect($plain->original()->getCustomProperty(MediaSizes::ORIGINAL))->toBeNull()
            ->and($media->getCustomProperty(MediaSizes::ORIGINAL))->toBe($uploaded)
            ->and($media->size)->toBeLessThan($uploaded);
        expect(LibraryStats::storage()['saved'])->toBe($uploaded - $media->size);
    });

    it('is the size of the first file when photos stored earlier are compressed again', function () {
        $photo = photoFromFile(Album::factory()->create(), noisyImageFile(1600, 1200));
        $stored = $photo->original()->size;

        useCompression(quality: 60);
        PhotoCompression::startExisting();
        PhotoCompression::compressExisting();
        $media = $photo->original()->refresh();
        $firstSize = $media->size;
        expect($media->getCustomProperty(MediaSizes::ORIGINAL))->toBe($stored)
            ->and($firstSize)->toBeLessThan($stored);

        useCompression(quality: 30);
        PhotoCompression::startExisting();
        PhotoCompression::compressExisting();

        $media->refresh();
        expect($media->size)->toBeLessThan($firstSize)
            ->and($media->getCustomProperty(MediaSizes::ORIGINAL))->toBe($stored);
    });
});

describe('the size of generated files', function () {
    it('is recorded for each conversion the media library completes', function () {
        $media = galleryPhoto(Album::factory()->create())->original()->refresh();

        $sizes = $media->getCustomProperty(MediaSizes::CONVERSIONS);
        $onDisk = collect(['preview', 'thumb', 'small'])->mapWithKeys(fn (string $name) => [$name => Storage::disk('gallery')->size($media->getPathRelativeToRoot($name))])->all();

        expect($sizes)->toBe($onDisk)
            ->and(min($sizes))->toBeGreaterThan(0)
            ->and(LibraryStats::storage())->toMatchArray(['generated' => array_sum($onDisk), 'pendingSizes' => 0]);
    });

    it('leaves the record alone when the file cannot be read', function () {
        $media = galleryPhoto(Album::factory()->create())->original()->refresh();
        $recorded = $media->getCustomProperty(MediaSizes::CONVERSIONS);

        MediaSizes::recordConversion($media, 'no-such-conversion');

        expect($media->refresh()->getCustomProperty(MediaSizes::CONVERSIONS))->toBe($recorded);
    });
});

describe('photos stored before sizes were recorded', function () {
    /** The photo's media row as it was before: no recorded sizes. */
    $forgetSizes = fn (Media $media) => Media::whereKey($media->getKey())->update(['custom_properties' => '{}']);

    it('get their sizes in the background, until all were looked at', function () use ($forgetSizes) {
        $media = collect(range(1, 3))->map(fn () => galleryPhoto(Album::factory()->create())->original());
        $media->each($forgetSizes);

        expect(LibraryStats::storage()['pendingSizes'])->toBe(3)
            ->and(MediaSizes::filling())->toBeTrue();

        // A pass that runs out of time stops before reading anything and does not finish.
        expect(MediaSizes::fillMissing(-1))->toBe(0)
            ->and(Setting::get(MediaSizes::DONE, false))->toBeFalse();

        expect(MediaSizes::fillMissing())->toBe(3)
            ->and(Setting::get(MediaSizes::DONE))->toBeTrue()
            ->and(Setting::get(MediaSizes::CURSOR))->toBeNull()
            ->and(MediaSizes::filling())->toBeFalse();

        cache()->flush();
        $stored = $media->first()->refresh();
        expect(array_keys($stored->getCustomProperty(MediaSizes::CONVERSIONS)))->toBe(['preview', 'thumb', 'small'])
            ->and(LibraryStats::storage()['pendingSizes'])->toBe(0);

        // Done is final: photos added later get their sizes when they are made.
        $forgetSizes($media->first());
        expect(MediaSizes::fillMissing())->toBe(0);
    });

    it('are worked through in batches, remembering where the last pass ended', function () {
        insertBareMedia(MediaSizes::BATCH + 1);

        expect(MediaSizes::fillMissing())->toBe(0)
            ->and(Setting::get(MediaSizes::DONE, false))->toBeFalse()
            ->and((int) Setting::get(MediaSizes::CURSOR))->toBe(Media::query()->orderBy('id')->skip(MediaSizes::BATCH - 1)->value('id'))
            ->and(MediaSizes::filling())->toBeTrue();

        expect(MediaSizes::fillMissing())->toBe(0)
            ->and(Setting::get(MediaSizes::DONE))->toBeTrue()
            ->and(Setting::get(MediaSizes::CURSOR))->toBeNull();
    });

    it('are measured by the background jobs', function () use ($forgetSizes) {
        $media = galleryPhoto(Album::factory()->create())->original();
        $forgetSizes($media);

        $this->artisan('gallery:background-jobs')->assertSuccessful();

        expect($media->refresh()->getCustomProperty(MediaSizes::CONVERSIONS))->toHaveKeys(['preview', 'thumb', 'small'])
            ->and(BackgroundJobs::lastRun())->not->toBeNull();
    });
});
