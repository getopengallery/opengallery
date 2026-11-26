<?php

use App\Enums\Role;
use App\Extensions\PluginSettings;
use Livewire\Livewire;
use Plugins\IdentityProviders\Filament\Settings;

beforeEach(fn () => actingAsRole(Role::Admin));

it('saves providers with an encrypted secret and keeps it when left empty', function () {
    $page = Livewire::test(Settings::class)
        ->set('data.providers', [[
            'id' => 'p1', 'name' => 'Keycloak', 'key' => 'Keycloak', 'type' => 'oidc', 'issuer' => IDP_ISSUER,
            'client_id' => 'gallery', 'client_secret' => 'top-secret', 'enabled' => true, 'show_on_login' => true,
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $stored = PluginSettings::get('identity-providers', 'providers')[0];
    expect($stored['key'])->toBe('keycloak')
        ->and($stored['client_secret'])->not->toBe('top-secret')
        ->and(PluginSettings::decrypt($stored['client_secret']))->toBe('top-secret');

    $page->set('data.providers.0.name', 'Keycloak (family)')->call('save');

    expect(PluginSettings::decrypt(PluginSettings::get('identity-providers', 'providers')[0]['client_secret']))->toBe('top-secret');
});

it('allows only one default login', function () {
    $provider = fn (string $key) => ['id' => $key, 'name' => $key, 'key' => $key, 'type' => 'oidc', 'issuer' => IDP_ISSUER, 'client_id' => 'c', 'enabled' => true, 'is_default' => true];

    Livewire::test(Settings::class)
        ->set('data.providers', [$provider('one'), $provider('two')])
        ->call('save')
        ->assertHasErrors(['data.providers' => 'Only one provider can be the default login.']);
});
