<?php

use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Pages\Dashboard;
use App\Models\Setting;
use App\Support\SiteSettings;
use App\Support\WebApp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

describe('with the app turned on', function () {
    it('describes the site as an installable app, linked from the site and the admin', function () {
        Setting::put(SiteSettings::NAME, 'Smith Family Photos');
        SiteSettings::apply();

        $this->get(route('web-app.manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => 'Smith Family Photos',
                'start_url' => url('/').'/',
                'scope' => url('/').'/',
                'display' => 'standalone',
                'theme_color' => '#FFFFFF',
                'background_color' => '#FFFFFF',
            ])
            ->assertJsonFragment(['src' => asset('web-app/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('web-app.manifest'), false)
            ->assertSee('navigator.serviceWorker.register', false);

        $this->actingAs(userWithRole())
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee(route('web-app.manifest'), false);
    });

    it('shows an offline page', function () {
        $this->get(route('web-app.offline'))
            ->assertOk()
            ->assertSee('You are offline');
    });

    it('does not serve icon sizes it does not make', function () {
        $this->get('/app-icons/64.png')->assertNotFound();
        $this->get(route('web-app.icon', ['size' => 192]))->assertNotFound();
    });

    it('keeps the built-in icons out of the folder Apache reserves', function () {
        expect(parse_url(WebApp::iconUrl(180), PHP_URL_PATH))->toBe('/web-app/icon-180.png')
            ->and(public_path('web-app/icon-180.png'))->toBeFile()
            ->and(public_path('web-app/icon-192.png'))->toBeFile()
            ->and(public_path('web-app/icon-512.png'))->toBeFile();
    });
});

describe('with the app turned off', function () {
    it('removes the app', function () {
        Setting::put(SiteSettings::WEB_APP, false);

        $this->get(route('web-app.manifest'))->assertNotFound();
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee(route('web-app.manifest'), false)
            ->assertDontSee('navigator.serviceWorker.register', false)
            ->assertSee('registration.unregister()', false);
    });
});

describe('without an app icon', function () {
    beforeEach(fn () => Storage::fake('local'));

    it('makes the home screen icon from a PNG favicon, on white', function () {
        $favicon = imagecreatetruecolor(64, 64);
        imagesavealpha($favicon, true);
        imagefill($favicon, 0, 0, imagecolorallocatealpha($favicon, 0, 0, 0, 127));
        imagefilledrectangle($favicon, 24, 24, 40, 40, imagecolorallocate($favicon, 255, 0, 0));
        ob_start();
        imagepng($favicon);
        Storage::disk('local')->put('branding/favicon.png', ob_get_clean());
        Setting::put(SiteSettings::FAVICON, 'branding/favicon.png');

        $page = $this->get(route('login'));
        $icon = $this->get(WebApp::iconUrl(180));

        $page->assertSee('rel="apple-touch-icon" href="'.e(WebApp::iconUrl(180)).'"', false);
        $icon->assertOk()->assertHeader('Content-Type', 'image/png');
        $image = imagecreatefromstring($icon->streamedContent());
        expect([imagesx($image), imagesy($image)])->toBe([180, 180])
            ->and(imagecolorsforindex($image, imagecolorat($image, 2, 2)))
            ->toMatchArray(['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 0]);
    });

    it('uses the built-in mark for a favicon it cannot convert', function (string $file) {
        Storage::disk('local')->put("branding/{$file}", 'icon');
        Setting::put(SiteSettings::FAVICON, "branding/{$file}");

        expect(WebApp::iconUrl(180))->toBe(asset('web-app/icon-180.png'));
    })->with(['favicon.svg', 'favicon.ico']);

    it('serves the built-in mark when a favicon turns out to be unreadable', function () {
        Storage::disk('local')->put('branding/favicon.png', 'not an image');
        Setting::put(SiteSettings::FAVICON, 'branding/favicon.png');

        $response = $this->get(WebApp::iconUrl(192));

        $response->assertOk();
        expect($response->baseResponse->getFile()->getPathname())->toBe(public_path('web-app/icon-192.png'));
    });

    it('makes the icon again when the favicon changes', function () {
        Storage::disk('local')->put('branding/favicon.png', UploadedFile::fake()->image('favicon.png', 64, 64)->getContent());
        Setting::put(SiteSettings::FAVICON, 'branding/favicon.png');
        $this->get(WebApp::iconUrl(192))->assertOk();
        $this->actingAs(userWithRole());

        Livewire::test(GeneralSettings::class)->set('data.favicon', null)->call('save')->assertHasNoErrors();

        expect(Storage::disk('local')->allFiles('branding'))->toBe([])
            ->and(WebApp::iconUrl(192))->toBe(asset('web-app/icon-192.png'));
    });
});

it('prefers the app icon over the favicon', function () {
    Storage::fake('local');
    Storage::disk('local')->put('branding/favicon.png', UploadedFile::fake()->image('favicon.png', 64, 64)->getContent());
    Storage::disk('local')->put('branding/app.png', UploadedFile::fake()->image('app.png', 600, 600)->getContent());
    Setting::put(SiteSettings::FAVICON, 'branding/favicon.png');
    Setting::put(SiteSettings::APP_ICON, 'branding/app.png');

    expect(WebApp::iconSource())->toBe('branding/app.png');
});
