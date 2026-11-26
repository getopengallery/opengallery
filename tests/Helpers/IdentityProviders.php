<?php

use App\Extensions\PluginSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Plugins\IdentityProviders\ProviderRepository;

const IDP_ISSUER = 'https://id.example.org/realms/family';

/**
 * Stores one provider ("family") with sensible defaults; $overrides win.
 *
 * @param  array<string, mixed>  $overrides
 */
function storeIdentityProvider(array $overrides = []): void
{
    app(ProviderRepository::class)->save([[
        'id' => 'test-id',
        'key' => 'family',
        'name' => 'Family ID',
        'description' => 'For relatives',
        'type' => 'oidc',
        'issuer' => IDP_ISSUER,
        'client_id' => 'gallery',
        'client_secret' => PluginSettings::encrypt('s3cret'),
        'enabled' => true,
        'show_on_login' => true,
        'create_users' => true,
        ...$overrides,
    ]]);
}

/**
 * Fakes discovery, token and userinfo endpoints; userinfo returns $claims.
 *
 * @param  array<string, mixed>  $claims
 */
function fakeIdentityProvider(array $claims): void
{
    Http::preventStrayRequests();
    Http::fake([
        IDP_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => IDP_ISSUER.'/auth',
            'token_endpoint' => IDP_ISSUER.'/token',
            'userinfo_endpoint' => IDP_ISSUER.'/userinfo',
        ]),
        IDP_ISSUER.'/token' => Http::response(['access_token' => 'at-123', 'token_type' => 'Bearer']),
        IDP_ISSUER.'/userinfo' => Http::response($claims),
    ]);
}

/** Runs the redirect + callback round trip through the "family" provider and returns the callback response. */
function signInWithIdentityProvider(string $route = 'idp.redirect'): TestResponse
{
    $redirect = test()->get(route($route, 'family'));
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    return test()->get(route('idp.callback', ['provider' => 'family', 'code' => 'the-code', 'state' => $query['state'] ?? 'missing']));
}

/**
 * The userinfo claims of Anna Weber; $extra wins.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function annaClaims(array $extra = []): array
{
    return ['sub' => 'uuid-anna', 'name' => 'Anna Weber', 'preferred_username' => 'anna', 'email' => 'anna@example.com', 'email_verified' => true, ...$extra];
}
