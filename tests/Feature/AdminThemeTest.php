<?php

use App\Enums\Role;
use App\Extensions\ThemeManager;
use App\Models\User;
use App\Support\AdminTheme;
use Filament\Support\Colors\Color;

it('uses the theme colours for the admin by default', function () {
    expect(AdminTheme::primary()[600])->toBe(expectedShade('#1A1A1A'))
        ->and(AdminTheme::gray()[500])->toBe(Color::Neutral[500])
        ->and(AdminTheme::gray()[950])->toBe(Color::convertToOklch('#1B1B1B'));
});

it('follows the palette chosen in the theme settings', function () {
    app(ThemeManager::class)->saveOptions('default', ['palette' => 'exp']);

    expect(AdminTheme::primary()[600])->toBe(expectedShade('#FF5C35'))
        ->and(AdminTheme::gray()[500])->not->toBe(Color::Neutral[500]) // greys take the palette's warm tint
        ->and(AdminTheme::gray()[950])->toBe(Color::convertToOklch('#181210'))
        ->and(AdminTheme::gray()[900])->toBe(Color::convertToOklch('#221916'));
});

it('follows custom colours only while they are turned on', function () {
    $custom = ['palette' => 'neutral', 'colors' => ['light' => ['primary' => '#B4235A'], 'dark' => ['canvas' => '#101010']]];

    app(ThemeManager::class)->saveOptions('default', [...$custom, 'custom_colors' => false]);
    expect(AdminTheme::primary()[600])->toBe(expectedShade('#1A1A1A'));

    app(ThemeManager::class)->saveOptions('default', [...$custom, 'custom_colors' => true]);
    expect(AdminTheme::primary()[600])->toBe(expectedShade('#B4235A'))
        ->and(AdminTheme::gray()[950])->toBe(Color::convertToOklch('#101010'));
});

it('applies the theme colours and corners in the admin panel', function () {
    app(ThemeManager::class)->saveOptions('default', ['palette' => 'moss', 'radius' => '0px']);

    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value))
        ->get('/admin')
        ->assertOk()
        ->assertSee('--og-primary: 63 90 60;', false)
        ->assertSee('--og-radius: 0px;', false)
        ->assertSee('--radius-lg: var(--og-radius, 6px);', false);
});

it('shows the site-style light/dark switch and account menu in the admin top bar', function () {
    $admin = tap(User::factory()->create(['name' => 'Ada Lovelace']))->assignRole(Role::Admin->value);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee("\$dispatch('theme-changed'", false)
        ->assertSee('og-account-trigger', false)
        ->assertSee('Ada Lovelace')
        ->assertSee(route('profile.edit'), false)
        ->assertSee(route('filament.admin.auth.logout'), false)
        ->assertDontSee('fi-theme-switcher', false)
        ->assertDontSee('fi-user-menu', false);
});
