<?php

use App\Enums\PhotoOrder;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\PhotoSettings;
use App\Models\Setting;
use App\Support\PhotoUploads;
use App\Support\SiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(fn () => actingAsRole(Role::Admin));

it('saves the default photo order', function () {
    Livewire::test(PhotoSettings::class)
        ->fillForm(['default_photo_order' => PhotoOrder::TakenDesc->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::defaultPhotoOrder())->toBe(PhotoOrder::TakenDesc);
});

it('saves the lightbox and download options, showing the seconds only with the slideshow on and the location option only with downloads on', function () {
    Livewire::test(PhotoSettings::class)
        ->assertFormSet(['downloads_enabled' => false, 'favorites_enabled' => true, 'slideshow_seconds' => 5])
        ->fillForm(['slideshow_enabled' => false, 'downloads_enabled' => false])
        ->assertFormFieldHidden('slideshow_seconds')
        ->assertFormFieldHidden('remove_location')
        ->fillForm([
            'downloads_enabled' => true,
            'favorites_enabled' => false,
            'photo_info_enabled' => false,
            'slideshow_enabled' => true,
            'slideshow_seconds' => 12,
        ])
        ->assertFormFieldVisible('slideshow_seconds')
        ->assertFormFieldVisible('remove_location')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::downloadsEnabled())->toBeTrue()
        ->and(SiteSettings::favoritesEnabled())->toBeFalse()
        ->and(SiteSettings::photoInfoEnabled())->toBeFalse()
        ->and(SiteSettings::slideshowEnabled())->toBeTrue()
        ->and(SiteSettings::slideshowSeconds())->toBe(12);
});

it('accepts slideshow seconds only within the allowed range', function (int $seconds, bool $accepted) {
    $page = Livewire::test(PhotoSettings::class)
        ->fillForm(['slideshow_enabled' => true, 'slideshow_seconds' => $seconds])
        ->call('save');

    $accepted ? $page->assertHasNoFormErrors() : $page->assertHasFormErrors(['slideshow_seconds']);
    expect(SiteSettings::slideshowSeconds())->toBe($accepted ? $seconds : 5);
})->with([
    'below the minimum' => [SiteSettings::SLIDESHOW_SECONDS_MIN - 1, false],
    'the minimum' => [SiteSettings::SLIDESHOW_SECONDS_MIN, true],
    'the maximum' => [SiteSettings::SLIDESHOW_SECONDS_MAX, true],
    'above the maximum' => [SiteSettings::SLIDESHOW_SECONDS_MAX + 1, false],
]);

describe('uploads', function () {
    beforeEach(function () {
        Storage::fake('local');
        config(['media-library.image_driver' => 'gd']);
    });

    it('saves the largest photo size and the accepted file types and enforces them', function () {
        saveSettingsPage(['upload_max_mb' => 5, 'upload_types' => ['image/jpeg', 'image/png']], PhotoSettings::class)->assertHasNoFormErrors();

        expect(Setting::get(SiteSettings::UPLOAD_TYPES))->toBe(['image/jpeg', 'image/png'])
            ->and(PhotoUploads::maxBytes())->toBe(5 * 1024 * 1024)
            ->and(config('media-library.max_file_size'))->toBe(5 * 1024 * 1024)
            ->and(PhotoUploads::acceptedExtensions())->toBe(['jpg', 'jpeg', 'jfif', 'png']);

        $this->postJson(route('uploads.photos'), [
            'upload' => (string) Str::uuid(),
            'batch' => (string) Str::uuid(),
            'target' => 'inbox',
            'name' => 'animation.gif',
            'size' => 3,
            'offset' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'abc'),
        ])->assertJsonValidationErrors(['name']);
    });

    it('refuses file types this server cannot process', function () {
        expect(PhotoUploads::processableTypes())->not->toContain('image/heic');

        saveSettingsPage(['upload_types' => ['image/jpeg', 'image/heic']], PhotoSettings::class)->assertHasFormErrors(['upload_types.1']);

        expect(Setting::get(SiteSettings::UPLOAD_TYPES))->toBeNull();
    });

    it('needs at least one file type and a sensible size', function () {
        saveSettingsPage(['upload_max_mb' => 0, 'upload_types' => []], PhotoSettings::class)
            ->assertHasFormErrors(['upload_max_mb' => 'min', 'upload_types' => 'required']);
    });
});
