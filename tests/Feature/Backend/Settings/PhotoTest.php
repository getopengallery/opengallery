<?php

use App\Enums\LightboxFooter;
use App\Enums\PhotoOrder;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\PhotoSettings;
use App\Models\Setting;
use App\Support\PhotoCompression;
use App\Support\PhotoUploads;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
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

it('saves what the lightbox shows below the photo, starting with description and date taken, and refuses other values', function () {
    Livewire::test(PhotoSettings::class)
        ->assertFormSet(['lightbox_footer' => LightboxFooter::DescriptionAndTaken])
        ->fillForm(['lightbox_footer' => 'nonsense'])
        ->call('save')
        ->assertHasFormErrors(['lightbox_footer']);

    expect(Setting::get(SiteSettings::LIGHTBOX_FOOTER))->toBeNull();

    Livewire::test(PhotoSettings::class)
        ->fillForm(['lightbox_footer' => LightboxFooter::Camera->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::lightboxFooter())->toBe(LightboxFooter::Camera);

    Setting::put(SiteSettings::LIGHTBOX_FOOTER, 'removed_option');

    expect(SiteSettings::lightboxFooter())->toBe(LightboxFooter::DescriptionAndTaken);

    Livewire::test(PhotoSettings::class)->assertFormSet(['lightbox_footer' => LightboxFooter::DescriptionAndTaken]);
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

describe('compression', function () {
    it('is off by default and shows its options only once it is turned on', function () {
        Livewire::test(PhotoSettings::class)
            ->assertFormSet(['compression_enabled' => false, 'compression_resize' => true, 'compression_max_px' => 3840, 'compression_reencode' => true, 'compression_quality' => 80])
            ->assertFormFieldHidden('compression_resize')
            ->assertFormFieldHidden('compression_max_px')
            ->assertFormFieldHidden('compression_reencode')
            ->assertFormFieldHidden('compression_quality')
            ->fillForm(['compression_enabled' => true])
            ->assertFormFieldVisible('compression_resize')
            ->assertFormFieldVisible('compression_max_px')
            ->assertFormFieldVisible('compression_reencode')
            ->assertFormFieldVisible('compression_quality')
            ->fillForm(['compression_resize' => false, 'compression_reencode' => false])
            ->assertFormFieldHidden('compression_max_px')
            ->assertFormFieldHidden('compression_quality');

        expect(SiteSettings::compressionEnabled())->toBeFalse()
            ->and(PhotoCompression::isActive())->toBeFalse();
    });

    it('saves what to do with new photos', function () {
        Livewire::test(PhotoSettings::class)
            ->fillForm(['compression_enabled' => true, 'compression_resize' => true, 'compression_max_px' => 2560, 'compression_reencode' => true, 'compression_quality' => 70])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(PhotoCompression::settings())->toBe(['max' => 2560, 'quality' => 70]);

        Livewire::test(PhotoSettings::class)
            ->assertFormSet(['compression_enabled' => true, 'compression_max_px' => 2560, 'compression_quality' => 70])
            ->fillForm(['compression_resize' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(PhotoCompression::settings())->toBe(['max' => null, 'quality' => 70]);
    });

    it('accepts the longest side and the quality only within the allowed range', function (string $field, int $value, bool $accepted) {
        $page = Livewire::test(PhotoSettings::class)
            ->fillForm(['compression_enabled' => true, 'compression_resize' => true, 'compression_reencode' => true, $field => $value])
            ->call('save');

        $accepted ? $page->assertHasNoFormErrors() : $page->assertHasFormErrors([$field]);
        expect(SiteSettings::compressionEnabled())->toBe($accepted);
    })->with([
        'longest side below the minimum' => ['compression_max_px', SiteSettings::COMPRESSION_MAX_PX_MIN - 1, false],
        'the smallest longest side' => ['compression_max_px', SiteSettings::COMPRESSION_MAX_PX_MIN, true],
        'the largest longest side' => ['compression_max_px', SiteSettings::COMPRESSION_MAX_PX_MAX, true],
        'longest side above the maximum' => ['compression_max_px', SiteSettings::COMPRESSION_MAX_PX_MAX + 1, false],
        'quality below the minimum' => ['compression_quality', SiteSettings::COMPRESSION_QUALITY_MIN - 1, false],
        'the lowest quality' => ['compression_quality', SiteSettings::COMPRESSION_QUALITY_MIN, true],
        'the highest quality' => ['compression_quality', SiteSettings::COMPRESSION_QUALITY_MAX, true],
        'quality above the maximum' => ['compression_quality', SiteSettings::COMPRESSION_QUALITY_MAX + 1, false],
    ]);

    it('offers to compress existing photos only while the saved settings compress something, and starts the run', function () {
        Livewire::test(PhotoSettings::class)
            ->assertDontSee('Compress existing photos')
            ->fillForm(['compression_enabled' => true])
            ->assertDontSee('Compress existing photos');

        Setting::put(SiteSettings::COMPRESSION_ENABLED, true);
        Setting::put(SiteSettings::COMPRESSION_RESIZE, false);
        Setting::put(SiteSettings::COMPRESSION_REENCODE, false);

        Livewire::test(PhotoSettings::class)->assertDontSee('Compress existing photos');

        Setting::put(SiteSettings::COMPRESSION_REENCODE, true);

        $action = TestAction::make('compressExisting')->schemaComponent('compressionActions', schema: 'form');

        Livewire::test(PhotoSettings::class)
            ->assertSee('Compress existing photos')
            ->assertSee('Photos stored before stay as they are')
            ->mountAction($action);

        expect(Setting::get(PhotoCompression::EXISTING_CURSOR))->toBeNull('asks for confirmation first');

        Livewire::test(PhotoSettings::class)
            ->callAction($action)
            ->assertNotified(__('Existing photos are being compressed'))
            ->assertSee('Existing photos are almost done.');

        expect(Setting::get(PhotoCompression::EXISTING_CURSOR))->toBe(0);
    });

    it('refuses to start compressing existing photos while compression is off', function () {
        Livewire::test(PhotoSettings::class)->call('compressExisting')->assertForbidden();

        expect(Setting::get(PhotoCompression::EXISTING_CURSOR))->toBeNull();
    });
});

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
