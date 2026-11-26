<?php

use App\Enums\Role;
use App\Extensions\PluginManager;
use App\Extensions\ThemeManager;
use App\Models\Album;
use App\Models\User;
use App\Support\AdminTheme;

it('ships a default theme with light and dark tokens', function () {
    $themes = app(ThemeManager::class);

    expect($themes->active()->slug)->toBe('default')
        ->and($themes->cssVariables())
        ->toContain(':root { --og-canvas: 255 255 255;')
        ->toContain(':root.dark { --og-canvas: 27 27 27;');
});

it('activates themes and inherits settings from the parent theme', function () {
    $themes = useFixtureThemes();
    expect($themes->active()->slug)->toBe('plain');

    $themes->activate('contact-sheet');

    expect($themes->active()->slug)->toBe('contact-sheet')
        ->and($themes->cssVariables())
        ->toContain('--og-primary: 194 65 12;')      // child override, hex converted
        ->toContain('--og-canvas: 255 255 255;')     // inherited from parent
        ->toContain(':root.dark { --og-primary: 237 237 237; }')
        ->not->toContain('display:none')             // unsafe values are dropped
        ->and(AdminTheme::primary()[600])->toBe(expectedShade('#C2410C'));
});

it('lets a theme choose block variants and override block views', function () {
    $themes = useFixtureThemes();
    $themes->activate('contact-sheet');
    $themes->boot();

    expect($themes->blockView('photo-grid'))->toBe('blocks.photo-grid.square')
        ->and($themes->blockView('album-grid'))->toBe('blocks.album-grid'); // unknown variant falls back

    $this->get(route('home'))->assertSee('contact-sheet footer');
});

it('serves theme assets without allowing path traversal', function () {
    useFixtureThemes();

    $this->get('/themes/contact-sheet/theme.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
    $this->get('/themes/contact-sheet/../theme.json')->assertNotFound();
    $this->get('/themes/contact-sheet/..%2Ftheme.json')->assertNotFound();
});

it('enables and disables plugins', function () {
    $plugins = app(PluginManager::class);

    expect($plugins->all()->has('example'))->toBeTrue()
        ->and($plugins->isEnabled('example'))->toBeFalse();

    $plugins->enable('example');
    expect($plugins->isEnabled('example'))->toBeTrue();

    $plugins->disable('example');
    expect($plugins->isEnabled('example'))->toBeFalse();
});

it('restricts the admin panel to staff', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::User->value))
        ->get('/admin')->assertForbidden();

    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value))
        ->get('/admin')->assertOk();
});

it('renders every admin page for an administrator', function () {
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $album = Album::factory()->create();

    $this->actingAs($admin);

    foreach (['/admin', '/admin/albums', '/admin/albums/create', "/admin/albums/{$album->slug}/edit",
        '/admin/users', '/admin/users/create', "/admin/users/{$admin->id}/edit",
        '/admin/groups', '/admin/roles', '/admin/themes', '/admin/plugins'] as $url) {
        $this->get($url)->assertOk();
    }
});

it('hides user, role and extension management from moderators', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value));

    $this->get('/admin/albums')->assertOk();
    foreach (['/admin/users', '/admin/roles', '/admin/groups', '/admin/themes', '/admin/plugins'] as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('sends guests from the admin panel to the shared site login', function () {
    $this->get('/admin')->assertRedirect(route('login'));
});
