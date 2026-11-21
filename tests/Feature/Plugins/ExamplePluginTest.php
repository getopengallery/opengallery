<?php

use App\Enums\Role;
use App\Extensions\DashboardWidgets;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;

/**
 * Plugins are loaded while the app boots, so these tests turn the Example
 * plugin on through GALLERY_PLUGINS and rebuild the app.
 */
function bootWithPlugins(string $plugins): void
{
    foreach ([fn ($value) => putenv("GALLERY_PLUGINS={$value}"), fn ($value) => $_ENV['GALLERY_PLUGINS'] = $_SERVER['GALLERY_PLUGINS'] = $value] as $set) {
        $set($plugins);
    }

    test()->refreshApplication();
    test()->restoreInMemoryDatabase();
}

afterEach(function () {
    putenv('GALLERY_PLUGINS=identity-providers');
    $_ENV['GALLERY_PLUGINS'] = $_SERVER['GALLERY_PLUGINS'] = 'identity-providers';
});

it('adds its admin page through Panel::configureUsing while enabled', function () {
    bootWithPlugins('identity-providers,example');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    $this->get('/admin')->assertOk()->assertSee('Example plugin');
    $this->get('/admin/plugins/example/hello')->assertOk()->assertSee('Hello from the Example plugin');
});

it('keeps the page to people who may manage plugins', function () {
    bootWithPlugins('identity-providers,example');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value));

    $this->get('/admin/plugins/example/hello')->assertForbidden();
});

it('has no admin page while the plugin is off', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    $this->get('/admin/plugins/example/hello')->assertNotFound();
    $this->get('/admin')->assertDontSee('Example plugin');
});

it('adds its dashboard widget while enabled, only for people who may manage plugins', function () {
    bootWithPlugins('identity-providers,example');

    expect(app(DashboardWidgets::class)->all())->toHaveKey('example.hello');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value))->get('/admin')->assertSee('This dashboard widget is added by the Example plugin');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value))->get('/admin')->assertDontSee('This dashboard widget is added by the Example plugin');
});

it('has no dashboard widget while the plugin is off', function () {
    expect(app(DashboardWidgets::class)->all())->not->toHaveKey('example.hello');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value))->get('/admin')->assertDontSee('This dashboard widget is added by the Example plugin');
});

it('drops a plugin widget from saved layouts once the plugin is off', function () {
    bootWithPlugins('identity-providers,example');
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $this->actingAs($admin);
    app(DashboardLayout::class)->save($admin, [['key' => 'example.hello', 'hidden' => false, 'size' => DashboardLayout::FULL]]);
    // Widgets the preset does not name follow it, in registry order.
    expect(array_key_last(DashboardLayout::defaults(app(DashboardLayout::class)->available())))->toBe('example.hello')
        ->and(array_column(app(DashboardLayout::class)->entries($admin), 'key'))->toContain('example.hello');

    bootWithPlugins('identity-providers');
    $this->actingAs($admin = $admin->fresh());

    expect(array_column(app(DashboardLayout::class)->entries($admin), 'key'))->not->toContain('example.hello')
        ->and($admin->dashboard_layout['widgets'][0]['key'])->toBe('example.hello');
    $this->get('/admin')->assertOk()->assertDontSee('This dashboard widget is added by the Example plugin');
});
