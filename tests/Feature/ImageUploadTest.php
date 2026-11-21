<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Forms\Components\ImageUpload;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
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

it('tells the field its image is stored after a successful save', function () {
    Livewire::test(GeneralSettings::class)
        ->set('data.logo', UploadedFile::fake()->image('logo.png', 200, 40))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched(ImageUpload::SAVED_EVENT, statePath: 'data.logo');

    expect(SiteSettings::assetPath('logo'))->not->toBeNull();
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

it('still rejects a file type the field does not accept on the server', function () {
    Livewire::test(GeneralSettings::class)
        ->set('data.favicon', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['data.favicon']);

    expect(SiteSettings::assetPath('favicon'))->toBeNull();
});

it('still rejects a file over the field limit on the server', function () {
    Livewire::test(GeneralSettings::class)
        ->set('data.favicon', UploadedFile::fake()->image('icon.png', 64, 64)->size(300))
        ->call('save')
        ->assertHasErrors(['data.favicon']);

    expect(SiteSettings::assetPath('favicon'))->toBeNull();
});
