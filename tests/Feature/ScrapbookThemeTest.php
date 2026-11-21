<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\ThemeManager;
use App\Filament\Pages\ThemeSettings;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The Scrapbook theme is the standalone counterpart to the Default theme:
 * no parent, its own blocks, stylesheet, tokens, settings and wording.
 */
function useScrapbookTheme(array $options = []): ThemeManager
{
    $themes = app(ThemeManager::class);
    $themes->activate('scrapbook');
    $themes->saveOptions('scrapbook', $options);
    $themes->boot();

    return $themes;
}

function scrapbookAlbumWithPhoto(): Album
{
    $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer fair']);
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    imagejpeg(imagecreatetruecolor(800, 600), $path);
    app(AddPhotoToAlbum::class)->handle($album, $path, 'Candy floss.jpg');

    return $album;
}

beforeEach(fn () => Storage::fake('gallery'));

it('renders albums and photos with its own blocks, stylesheet and tokens', function () {
    useScrapbookTheme();
    $album = scrapbookAlbumWithPhoto();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('theme.asset', ['theme' => 'scrapbook', 'path' => 'scrapbook.css']).'?v=', false)
        ->assertSee('--og-pop-1: 255 79 139;', false)
        ->assertSee('class="sb-board sb-wobble"', false)
        ->assertSee('Summer fair')
        ->assertSee('Fresh prints inside');

    $this->get(route('albums.show', $album))
        ->assertOk()
        ->assertSee('class="sb-pile sb-wobble"', false)
        ->assertSee('Candy floss');

    $this->get('/themes/scrapbook/scrapbook.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
});

it('follows its settings for photo layout, tilt, ticker, palette and paper', function () {
    $themes = useScrapbookTheme(['photo_grid_layout' => 'polaroids', 'wobble' => false, 'ticker' => false, 'palette' => 'lemonade', 'paper' => 'none']);
    $album = scrapbookAlbumWithPhoto();

    expect($themes->blockView('photo-grid'))->toBe('blocks.photo-grid.polaroids')
        ->and($themes->cssVariables())->toContain('--og-pop-3: 139 214 80;', '--og-paper: none;');

    $this->get(route('albums.show', $album))
        ->assertOk()
        ->assertSee('class="sb-board"', false)
        ->assertDontSee('sb-wobble')
        ->assertDontSee('sb-ticker');
});

it('saves its settings from the admin, including CSS values bound to its own tokens', function () {
    useScrapbookTheme();
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
    $grid = 'linear-gradient(rgb(var(--og-line)) 1px, transparent 1px), linear-gradient(90deg, rgb(var(--og-line)) 1px, transparent 1px)';

    Livewire::test(ThemeSettings::class)
        ->assertSee('Whimsy')
        ->set('data.palette', 'circus')
        ->set('data.paper', $grid)
        ->set('data.ticker_text', 'Bake sale on Saturday')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(ThemeManager::class)->cssVariables())
        ->toContain('--og-header-canvas: 200 16 46;', "--og-paper: {$grid};", "--og-font-display: 'Cooper Black'");

    $this->get(route('home'))->assertSee('Bake sale on Saturday');
});

it('shows guests its landing collage and notebook login', function () {
    Setting::put(SiteSettings::PUBLIC_ALBUMS, false);
    SiteSettings::apply();
    useScrapbookTheme(['landing_heading' => 'Welcome to the fair', 'doodles' => false]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Welcome to the fair')
        ->assertSee('sb-collage', false)
        ->assertDontSee('sb-doodles');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('sb-auth-card', false)
        ->assertSee('Psst, the good photos are inside.');
});

it('changes core wording and the not-found page', function () {
    useScrapbookTheme();
    $album = Album::factory()->visibility(Visibility::Public)->create();

    $this->get(route('albums.show', $album))->assertSee('Nothing glued in here yet. Bring the glitter!');

    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('This page wandered off');
});
