<?php

use App\Enums\CollaboratorRole;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\ThemeManager;
use App\Models\Album;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

describe('the account menu in the header', function () {
    it('offers the admin under Profile in the account menu, not among the header links', function () {
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        // Desktop account menu and mobile menu: Profile, then Admin, then Log out.
        expect(substr_count($html, '>Admin</a>'))->toBe(2)
            ->and(strpos($html, '>Profile</a>'))->toBeLessThan(strpos($html, '>Admin</a>'))
            ->and(strpos($html, '>Admin</a>'))->toBeLessThan(strpos($html, 'Log out'));
    });

    it('offers the admin only to members who may open it, also through a role on an album', function () {
        $member = tap(User::factory()->create())->assignRole(Role::User->value);
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $collaborator = addCollaborator($album, CollaboratorRole::Contributor);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Profile')
            ->assertDontSee(url('/admin'), false);
        $this->actingAs($collaborator)->get(route('home'))->assertSee(url('/admin'), false);
    });

    it('shows the unread count on the bell in the site header', function () {
        $grace = User::factory()->create();
        sharedCamp($grace);
        sharedCamp($grace);

        $this->actingAs($grace)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Notifications, 2 unread');
    });
});

describe('the site name and look', function () {
    it('uses the configured site name across the site and adds custom CSS without letting it break out of its style tag', function () {
        applySiteSettings([SiteSettings::NAME => 'Scout Troop 7']);
        themeOptions(['custom_css' => 'h1 { letter-spacing: 0 } </style><script>alert(1)</script>']);

        $this->get(route('login'))
            ->assertSee('Scout Troop 7')
            ->assertSee('h1 { letter-spacing: 0 }', false)
            ->assertDontSee('</style><script>', false);
    });

    it('applies the chosen palette, and custom colours only while they are turned on', function () {
        themeOptions(['palette' => 'moss', 'custom_colors' => false, 'colors' => ['light' => ['primary' => '#FF0000']], 'radius' => '14px']);
        $css = app(ThemeManager::class)->cssVariables();

        expect($css)->toContain('--og-primary: 63 90 60;', '--og-radius: 14px;', ':root.dark { ')
            ->not->toContain('255 0 0');

        themeOptions(['palette' => 'moss', 'custom_colors' => true, 'colors' => ['light' => ['primary' => '#FF0000'], 'dark' => ['primary' => '']]]);

        expect(app(ThemeManager::class)->cssVariables())->toContain('--og-primary: 255 0 0;');
    });

    it('lets a palette set corners and typeface until the owner picks their own, and keeps the header in view only when turned on', function () {
        $this->get(route('home'))->assertDontSee('sticky top-0', false);

        themeOptions(['palette' => 'neutral']);
        expect(app(ThemeManager::class)->cssVariables())->toContain('--og-radius: 6px;', "--og-font-sans: 'Hanken Grotesk Variable';")
            ->not->toContain('--og-header-canvas'); // header follows the page colours

        themeOptions(['palette' => 'exp', 'header_sticky' => true]);
        expect(app(ThemeManager::class)->cssVariables())->toContain('--og-primary: 255 92 53;', '--og-radius: 14px;', '--og-font-sans: ui-serif, Georgia, serif;')
            ->toContain('--og-header-canvas: 255 92 53;', '--og-header-ink: 255 255 255;'); // the orange header from the logo
        $this->get(route('home'))->assertSee('class="og-header', false)->assertSee('sticky top-0', false);

        themeOptions(['palette' => 'exp', 'radius' => '0px', 'font' => "'Hanken Grotesk Variable'"]);
        expect(app(ThemeManager::class)->cssVariables())->toContain('--og-radius: 0px;', "--og-font-sans: 'Hanken Grotesk Variable';")
            ->not->toContain('14px', 'ui-serif');
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
});

describe('the header and footer pages', function () {
    it('links the pages chosen for the header and footer, skipping drafts, reading them once per page view', function () {
        [$imprint, $about] = Page::factory()->count(2)->sequence(['title' => 'Imprint'], ['title' => 'About us'])->create();
        $draft = Page::factory()->draft()->create(['title' => 'Secret draft']);
        themeOptions(['header_pages' => [$about->id], 'footer_pages' => [$imprint->id, $about->id, $draft->id], 'footer_text' => 'Scout troop 7, since 1987']);
        DB::enableQueryLog();

        $this->get(route('home'))
            ->assertSeeInOrder(['About us', 'Scout troop 7, since 1987', 'Imprint'])
            ->assertDontSee('Secret draft');

        $pageQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "pages"'));
        expect($pageQueries)->toHaveCount(1);
    });
});

describe('the landing page', function () {
    it('shows the landing page content and image from the theme', function () {
        Storage::fake('local');
        Storage::disk('local')->put('themes/default/lake.jpg', 'jpeg-bytes');
        Setting::put(SiteSettings::PUBLIC_ALBUMS, false);
        SiteSettings::apply();
        themeOptions(['landing_layout' => 'split', 'landing_heading' => 'Hello, Smith family', 'landing_image' => 'themes/default/lake.jpg', 'landing_show_register' => false]);

        $this->get(route('home'))
            ->assertSee('Hello, Smith family')
            ->assertSee(route('theme.upload', ['path' => 'themes/default/lake.jpg']), false)
            ->assertDontSee('og-btn-secondary px-5 py-2.5', false)
            ->assertDontSee('backdrop-blur', false);

        $this->get(route('theme.upload', ['path' => 'themes/default/lake.jpg']))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('theme.upload', ['path' => 'branding/logo.png']))->assertNotFound();
    });

    it('keeps the menu button, panel and log-in link in the header for a guest on the landing page', function () {
        applySiteSettings([SiteSettings::PUBLIC_ALBUMS => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('aria-controls="site-menu"', false)
            ->assertSee('id="site-menu"', false)
            ->assertSee('>Log in</a>', false);
    });
});
