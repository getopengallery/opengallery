<?php

use App\Enums\Role;
use App\Extensions\ThemeManager;
use App\Filament\Pages\ThemeSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

describe('as an admin', function () {
    beforeEach(function () {
        Storage::fake('local');
        actingAsRole(Role::Admin);
    });

    it('saves theme settings from the admin and removes replaced images', function () {
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

    it('saves the Scrapbook settings, including CSS values bound to its own tokens', function () {
        useScrapbookTheme();
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
});

it('shows a theme\'s own texts from its language files in the reader\'s language', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin, ['locale' => 'de']));

    $this->get(route('filament.admin.pages.themes.settings', ['theme' => 'default']))
        ->assertOk()
        ->assertSee('Farben')
        ->assertSee('Schwarz-Weiß');
});
