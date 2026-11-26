<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\ThemeManager;
use App\Filament\Pages\ThemeSettings;
use App\Models\Album;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('applies the chosen palette, and custom colours only while they are turned on', function () {
    themeOptions(['palette' => 'moss', 'custom_colors' => false, 'colors' => ['light' => ['primary' => '#FF0000']], 'radius' => '14px']);
    $css = app(ThemeManager::class)->cssVariables();

    expect($css)->toContain('--og-primary: 63 90 60;', '--og-radius: 14px;', ':root.dark { ')
        ->not->toContain('255 0 0');

    themeOptions(['palette' => 'moss', 'custom_colors' => true, 'colors' => ['light' => ['primary' => '#FF0000'], 'dark' => ['primary' => '']]]);

    expect(app(ThemeManager::class)->cssVariables())->toContain('--og-primary: 255 0 0;');
});

it('switches header, footer, landing and login layouts from the settings', function () {
    $themes = app(ThemeManager::class);
    expect($themes->blockView('photo-grid'))->toBe('blocks.photo-grid');

    themeOptions(['header_layout' => 'centered', 'footer_layout' => 'columns', 'landing_layout' => 'cover', 'auth_layout' => 'split', 'photo_grid_layout' => 'square', 'album_grid_layout' => 'large']);

    expect([$themes->blockView('site-header'), $themes->blockView('site-footer'), $themes->blockView('landing'), $themes->blockView('auth-shell'), $themes->blockView('photo-grid'), $themes->blockView('album-grid')])
        ->toBe(['blocks.site-header.centered', 'blocks.site-footer.columns', 'blocks.landing.cover', 'blocks.auth-shell.split', 'blocks.photo-grid.square', 'blocks.album-grid.large']);

    // These layouts belong to the default theme, not to core.
    expect(view()->getFinder()->find('blocks.site-header.centered'))->toStartWith(base_path('themes/default/views/'))
        ->and(view()->getFinder()->find('blocks.album-grid.large'))->toStartWith(base_path('themes/default/views/'))
        ->and(view()->getFinder()->find('blocks.site-header'))->toStartWith(resource_path('views/'));

    themeOptions(['header_layout' => 'does-not-exist']);
    expect($themes->blockView('site-header'))->toBe('blocks.site-header');
});

it('links the pages chosen for the header and footer, skipping drafts', function () {
    [$imprint, $about] = Page::factory()->count(2)->sequence(['title' => 'Imprint'], ['title' => 'About us'])->create();
    $draft = Page::factory()->draft()->create(['title' => 'Secret draft']);
    themeOptions(['header_pages' => [$about->id], 'footer_pages' => [$imprint->id, $draft->id], 'footer_text' => 'Scout troop 7, since 1987']);

    $this->get(route('home'))
        ->assertSeeInOrder(['About us', 'Scout troop 7, since 1987', 'Imprint'])
        ->assertDontSee('Secret draft');
});

it('reads the pages for the header and footer once per page view', function () {
    [$imprint, $about] = Page::factory()->count(2)->sequence(['title' => 'Imprint'], ['title' => 'About us'])->create();
    themeOptions(['header_pages' => [$about->id], 'footer_pages' => [$imprint->id, $about->id]]);
    DB::enableQueryLog();

    $this->get(route('home'))->assertOk();

    $pageQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "pages"'));
    expect($pageQueries)->toHaveCount(1);
});

it('shows the landing page content and image from the theme', function () {
    Storage::fake('local');
    Storage::disk('local')->put('themes/default/lake.jpg', 'jpeg-bytes');
    Setting::put(SiteSettings::PUBLIC_ALBUMS, false);
    SiteSettings::apply();
    themeOptions(['landing_layout' => 'split', 'landing_heading' => 'Hello, Smith family', 'landing_image' => 'themes/default/lake.jpg', 'landing_show_register' => false]);

    $this->get(route('home'))
        ->assertSee('Hello, Smith family')
        ->assertSee(route('theme.upload', ['path' => 'themes/default/lake.jpg']), false)
        ->assertDontSee('Create account');

    $this->get(route('theme.upload', ['path' => 'themes/default/lake.jpg']))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('theme.upload', ['path' => 'branding/logo.png']))->assertNotFound();
});

it('opens the album list with the landing page when the theme asks for it', function () {
    Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp']);
    themeOptions(['landing_heading' => 'Welcome to troop 7', 'landing_layout' => 'cover']);

    $this->get(route('home'))->assertSee('Summer camp')->assertDontSee('Welcome to troop 7');

    themeOptions(['landing_on_home' => 'guests', 'landing_heading' => 'Welcome to troop 7', 'landing_layout' => 'cover']);

    $this->get(route('home'))
        ->assertSeeInOrder(['Welcome to troop 7', 'View albums', 'id="albums"', 'Summer camp'], false)
        ->assertDontSee('The albums here are private.');
    $this->actingAs(User::factory()->create())->get(route('home'))
        ->assertSee('Summer camp')
        ->assertDontSee('Welcome to troop 7');

    themeOptions(['landing_on_home' => 'everyone', 'landing_heading' => 'Welcome to troop 7']);

    $this->get(route('home'))->assertSeeInOrder(['Welcome to troop 7', 'Summer camp']);
});

it('adds custom CSS without letting it break out of its style tag', function () {
    themeOptions(['custom_css' => 'h1 { letter-spacing: 0 } </style><script>alert(1)</script>']);

    $this->get(route('login'))
        ->assertSee('h1 { letter-spacing: 0 }', false)
        ->assertDontSee('</style><script>', false);
});

it('saves theme settings from the admin and removes replaced images', function () {
    Storage::fake('local');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
    Storage::disk('local')->put('themes/default/old.jpg', 'x');
    themeOptions(['auth_image' => 'themes/default/old.jpg']);

    Livewire::test(ThemeSettings::class)
        ->set('data.palette', 'exp')
        ->set('data.header_layout', 'minimal')
        ->set('data.radius', '')
        ->set('data.font', 'system-ui, sans-serif')
        ->set('data.auth_image', null)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(ThemeManager::class)->option('palette'))->toBe('exp')
        ->and(app(ThemeManager::class)->option('header_layout'))->toBe('minimal')
        ->and(app(ThemeManager::class)->cssVariables())
        ->toContain('--og-radius: 14px;', '--og-font-sans: system-ui, sans-serif;'); // corners from the palette, typeface chosen
    Storage::disk('local')->assertMissing('themes/default/old.jpg');
});

it('keeps theme settings away from staff who cannot manage themes', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value))
        ->get(ThemeSettings::getUrl())
        ->assertForbidden();
});

it('lets a palette set corners and typeface until the owner picks their own', function () {
    themeOptions(['palette' => 'neutral']);
    expect(app(ThemeManager::class)->cssVariables())->toContain('--og-radius: 6px;', "--og-font-sans: 'Hanken Grotesk Variable';");

    expect(app(ThemeManager::class)->cssVariables())->not->toContain('--og-header-canvas'); // header follows the page colours

    themeOptions(['palette' => 'exp']);
    expect(app(ThemeManager::class)->cssVariables())->toContain('--og-primary: 255 92 53;', '--og-radius: 14px;', '--og-font-sans: ui-serif, Georgia, serif;')
        ->toContain('--og-header-canvas: 255 92 53;', '--og-header-ink: 255 255 255;'); // the orange header from the logo
    $this->get(route('home'))->assertSee('class="og-header', false);

    themeOptions(['palette' => 'exp', 'radius' => '0px', 'font' => "'Hanken Grotesk Variable'"]);
    expect(app(ThemeManager::class)->cssVariables())->toContain('--og-radius: 0px;', "--og-font-sans: 'Hanken Grotesk Variable';")
        ->not->toContain('14px', 'ui-serif');
});

it('keeps the header in view while scrolling only when turned on', function () {
    $this->get(route('home'))->assertDontSee('sticky top-0', false);

    themeOptions(['header_sticky' => true]);

    $this->get(route('home'))->assertSee('sticky top-0', false);
});
