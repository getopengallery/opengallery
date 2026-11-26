<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\AppSettings;
use App\Models\Setting;
use App\Support\SiteSettings;
use App\Support\WebApp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    actingAsRole(Role::Admin);
});

it('makes square icons from an uploaded app icon', function () {
    Livewire::test(AppSettings::class)
        ->set('data.app_icon', UploadedFile::fake()->image('icon.png', 800, 600))
        ->call('save')
        ->assertHasNoErrors();

    $manifest = $this->get(route('web-app.manifest'))->json();
    $icon = $this->get(WebApp::iconUrl(512));

    expect(collect($manifest['icons'])->pluck('src')->unique()->values()->all())
        ->toBe([WebApp::iconUrl(192), WebApp::iconUrl(512)])
        ->and(collect($manifest['icons'])->pluck('purpose')->unique()->all())->toBe(['any']);
    $icon->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(getimagesizefromstring($icon->streamedContent())[0])->toBe(512)
        ->and(getimagesizefromstring($icon->streamedContent())[1])->toBe(512);
});

it('forgets the generated icons when the app icon is removed', function () {
    Storage::disk('local')->put('branding/icon.png', UploadedFile::fake()->image('icon.png', 600, 600)->getContent());
    Setting::put(SiteSettings::APP_ICON, 'branding/icon.png');
    $url = WebApp::iconUrl(192);
    $this->get($url)->assertOk();

    Livewire::test(AppSettings::class)->set('data.app_icon', null)->call('save')->assertHasNoErrors();

    expect(Storage::disk('local')->allFiles('branding'))->toBe([])
        ->and(WebApp::iconUrl(192))->toBe(asset('web-app/icon-192.png'));
    $this->get($url)->assertNotFound();
});
