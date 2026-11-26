<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\ThemeManager;
use App\Filament\Clusters\Settings\Pages\AccessSettings;
use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Filament\Clusters\Settings\Pages\AppSettings;
use App\Filament\Clusters\Settings\Pages\BackgroundJobSettings;
use App\Filament\Clusters\Settings\Pages\EmailSettings;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Clusters\Settings\Pages\PhotoSettings;
use App\Filament\Clusters\Settings\Pages\SearchEngineSettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\ActivityEvents\Pages\ManageActivityEvents;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('saves settings from the admin page', function () {
    Storage::fake('local');
    $this->actingAs(userWithRole());

    Livewire::test(GeneralSettings::class)
        ->set('data.name', 'Lighthouse Family Photos')
        ->set('data.favicon', UploadedFile::fake()->image('icon.png', 64, 64))
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(AccessSettings::class)
        ->set('data.registration', false)
        ->set('data.public_albums', false)
        ->set('data.guest_home', 'login')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(SiteSettings::NAME))->toBe('Lighthouse Family Photos')
        ->and(Setting::get(SiteSettings::REGISTRATION))->toBeFalse()
        ->and(Setting::get(SiteSettings::PUBLIC_ALBUMS))->toBeFalse()
        ->and(Setting::get(SiteSettings::GUEST_HOME))->toBe('login')
        ->and(SiteSettings::assetPath('favicon'))->not->toBeNull()
        ->and(config('app.name'))->toBe('Lighthouse Family Photos');
});

it('keeps the guest start page when it is hidden in the form', function () {
    applySiteSettings([SiteSettings::GUEST_HOME => 'login']);
    $this->actingAs(userWithRole());

    Livewire::test(AccessSettings::class)->set('data.public_albums', true)->call('save');

    expect(Setting::get(SiteSettings::GUEST_HOME))->toBe('login');
});

it('opens the first settings page from the settings entry', function () {
    $this->actingAs(userWithRole())->get(SettingsCluster::getUrl())->assertRedirect(GeneralSettings::getUrl());
});

it('lists the settings topics directly in the main menu', function () {
    $this->actingAs(userWithRole());

    $menu = adminMenu();

    expect($menu['Settings'])->toBe(['General', 'App', 'Search engines', 'Access', 'Photos', 'Tags', 'E-mail'])
        ->and($menu['System'])->toBe(['Activity log', 'Background jobs', 'Updates']);
});

it('shows a settings page without a menu of its own', function () {
    $this->actingAs(userWithRole())->get(GeneralSettings::getUrl())
        ->assertOk()
        ->assertDontSee('fi-page-has-sub-navigation');
});

it('offers the log settings on the activity log to people who may change settings', function () {
    $this->actingAs(tap(User::factory()->create())->givePermissionTo(Permission::AccessAdmin->value, Permission::ViewActivity->value, Permission::ManageSettings->value));

    Livewire::test(ManageActivityEvents::class)->assertActionVisible('logSettings');
});

it('hides the log settings button from people who may only read the activity log', function () {
    $this->actingAs(tap(User::factory()->create())->givePermissionTo(Permission::AccessAdmin->value, Permission::ViewActivity->value));

    Livewire::test(ManageActivityEvents::class)->assertActionHidden('logSettings');
});

it('keeps the activity log highlighted in the menu on the log settings page', function () {
    $html = $this->actingAs(userWithRole())->get(ActivityLogSettings::getUrl())->assertOk()->getContent();

    preg_match('/<li[^>]*fi-sidebar-item[^>]*fi-active[^>]*>.*?<\/li>/s', $html, $activeItem);

    expect($activeItem[0] ?? '')->toContain('Activity log');
});

it('lists the log settings in the menu for people who may change settings but not open the log', function () {
    $this->actingAs(tap(User::factory()->create())->givePermissionTo(Permission::AccessAdmin->value, Permission::ManageSettings->value));

    expect(adminMenu()['System'])->toBe(['Log settings', 'Background jobs', 'Updates']);
});

it('leaves the settings of other pages alone when a page is saved', function () {
    applySiteSettings([SiteSettings::NAME => 'Scout Troop 7']);
    $this->actingAs(userWithRole());

    Livewire::test(AccessSettings::class)->set('data.registration', false)->call('save')->assertHasNoErrors();

    expect(Setting::get(SiteSettings::NAME))->toBe('Scout Troop 7')
        ->and(Setting::get(SiteSettings::REGISTRATION))->toBeFalse();
});

it('forbids every settings page to users who may not manage settings', function (string $page) {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value))
        ->get($page::getUrl())->assertForbidden();
})->with([
    'general' => GeneralSettings::class,
    'app' => AppSettings::class,
    'search engines' => SearchEngineSettings::class,
    'access' => AccessSettings::class,
    'photos' => PhotoSettings::class,
    'e-mail' => EmailSettings::class,
    'activity log' => ActivityLogSettings::class,
    'background jobs' => BackgroundJobSettings::class,
]);

it('is only available to users who may manage settings', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value))
        ->get(SettingsCluster::getUrl())->assertForbidden();

    $this->actingAs(userWithRole())->get(GeneralSettings::getUrl())->assertOk();
});

it('uses the configured site name across the site', function () {
    applySiteSettings([SiteSettings::NAME => 'Scout Troop 7']);

    $this->get(route('login'))->assertSee('Scout Troop 7');
});

it('closes registration when it is turned off', function () {
    applySiteSettings([SiteSettings::REGISTRATION => false]);

    $this->get('/register')->assertNotFound();
    $this->get(route('login'))->assertDontSee('Create an account');
});

describe('with public albums turned off', function () {
    beforeEach(fn () => applySiteSettings([SiteSettings::PUBLIC_ALBUMS => false]));

    it('shows guests a landing page instead of albums', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        app(ThemeManager::class)->saveOptions('default', ['landing_heading' => 'Welcome to the Smiths', 'landing_text' => 'Ask Anna for an account.']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Welcome to the Smiths')
            ->assertSee('Ask Anna for an account.')
            ->assertDontSee($album->title);

        $this->get(route('albums.show', $album))->assertNotFound();
    });

    it('can send guests straight to the login page', function () {
        applySiteSettings([SiteSettings::GUEST_HOME => 'login']);

        $this->get(route('home'))->assertRedirect(route('login'));
    });

    it('still shows public albums to logged-in users', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::User->value));

        $this->get(route('home'))->assertSee($album->title);
        $this->get(route('albums.show', $album))->assertOk();
    });

    it('keeps share links for unlisted albums working', function () {
        $album = Album::factory()->visibility(Visibility::Unlisted)->create();

        $this->get(route('albums.shared', $album->share_token));
        $this->get(route('albums.show', $album))->assertOk();
    });
});

it('serves uploaded branding files with a script-blocking policy', function () {
    Storage::fake('local');
    Storage::disk('local')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    applySiteSettings([SiteSettings::LOGO => 'branding/logo.svg']);

    $this->get(SiteSettings::assetUrl('logo'))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:");
    $this->get(route('home'))->assertSee(SiteSettings::assetUrl('logo'), false);
    $this->get('/site/favicon')->assertNotFound();
});
