<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Forms\Components\ImageUpload;
use App\Models\Setting;
use App\Support\Locales;
use App\Support\SiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    actingAsRole(Role::Admin);
});

it('saves the site name, favicon and logo, and tells the image fields their image is stored', function () {
    Livewire::test(GeneralSettings::class)
        ->set('data.name', 'Lighthouse Family Photos')
        ->set('data.favicon', UploadedFile::fake()->image('icon.png', 64, 64))
        ->set('data.logo', UploadedFile::fake()->image('logo.png', 200, 40))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched(ImageUpload::SAVED_EVENT, statePath: 'data.logo');

    expect(Setting::get(SiteSettings::NAME))->toBe('Lighthouse Family Photos')
        ->and(SiteSettings::assetPath('favicon'))->not->toBeNull()
        ->and(SiteSettings::assetPath('logo'))->not->toBeNull()
        ->and(config('app.name'))->toBe('Lighthouse Family Photos');
});

it('shows the stored logo in the image field without the old upload widget', function () {
    Storage::disk('local')->put('branding/logo.png', UploadedFile::fake()->image('logo.png')->getContent());
    Setting::put(SiteSettings::LOGO, 'branding/logo.png');

    $component = Livewire::test(GeneralSettings::class);

    $component->assertOk()
        ->assertSeeHtml('ogImageUpload(')
        ->assertSeeHtml('is-on-light')
        ->assertSeeHtml('is-on-dark')
        ->assertDontSeeHtml('fileUploadFormComponent');
    expect($component->html())->toContain('branding')
        ->and($component->html())->toContain('Up to 1 MB')
        ->and($component->html())->toContain('PNG, SVG, WebP or JPG');
});

it('keeps the not saved state and stores nothing when the form is invalid', function () {
    Livewire::test(GeneralSettings::class)
        ->set('data.name', '')
        ->set('data.logo', UploadedFile::fake()->image('logo.png', 200, 40))
        ->call('save')
        ->assertHasErrors(['data.name'])
        ->assertNotDispatched(ImageUpload::SAVED_EVENT);

    expect(SiteSettings::assetPath('logo'))->toBeNull();
});

it('still rejects a favicon the field does not accept on the server', function (Closure $file) {
    Livewire::test(GeneralSettings::class)
        ->set('data.favicon', $file())
        ->call('save')
        ->assertHasErrors(['data.favicon']);

    expect(SiteSettings::assetPath('favicon'))->toBeNull();
})->with([
    'a file type it does not accept' => [fn () => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
    'a file over its limit' => [fn () => UploadedFile::fake()->image('icon.png', 64, 64)->size(300)],
]);

it('saves the default language', function () {
    Livewire::test(GeneralSettings::class)
        ->set('data.locale', 'de')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(SiteSettings::LOCALE))->toBe('de')
        ->and(Locales::default())->toBe('de');
});

it('rejects a default language core has no translation for', function () {
    Livewire::test(GeneralSettings::class)
        ->set('data.locale', 'xx')
        ->call('save')
        ->assertHasErrors(['data.locale']);

    expect(Setting::get(SiteSettings::LOCALE))->toBeNull();
});
