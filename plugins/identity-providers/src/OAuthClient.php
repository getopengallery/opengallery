<?php

namespace Plugins\IdentityProviders;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Minimal OAuth 2.0 / OpenID Connect client for the authorization code flow
 * with PKCE (S256).
 *
 * Profile data is read from the provider's userinfo endpoint with the access
 * token obtained directly from the token endpoint over TLS, so the ID token
 * does not need to be verified here (OpenID Connect Core 1.0, 3.1.3.7).
 */
class OAuthClient
{
    private const GITHUB = [
        'authorize' => 'https://github.com/login/oauth/authorize',
        'token' => 'https://github.com/login/oauth/access_token',
        'userinfo' => 'https://api.github.com/user',
    ];

    public function __construct(private IdentityProvider $provider) {}

    public function authorizationUrl(string $state, string $verifier): string
    {
        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $this->provider->clientId(),
            'redirect_uri' => $this->provider->callbackUrl(),
            'scope' => $this->provider->scopes(),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], fn ($value) => $value !== '');

        $endpoint = $this->endpoint('authorize');

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * Exchanges the authorization code and returns the user's claims.
     *
     * @return array<string, mixed>
     */
    public function claims(string $code, string $verifier): array
    {
        $token = $this->accessToken($code, $verifier);

        $claims = $this->get($this->endpoint('userinfo'), $token);

        if ($this->provider->type() === IdentityProvider::GITHUB && empty($claims['email'])) {
            $claims = [...$claims, ...$this->githubEmail($token)];
        }

        if ($this->provider->type() === IdentityProvider::GITHUB && ! isset($claims['email_verified'])) {
            // GitHub only exposes an address on /user once it is verified.
            $claims['email_verified'] = filled($claims['email'] ?? null);
        }

        return $claims;
    }

    private function accessToken(string $code, string $verifier): string
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->provider->callbackUrl(),
            'code_verifier' => $verifier,
        ];

        $request = Http::asForm()->acceptJson()->timeout(15);

        if ($this->provider->tokenAuth() === 'basic') {
            $request = $request->withBasicAuth(rawurlencode($this->provider->clientId()), rawurlencode((string) $this->provider->clientSecret()));
        } else {
            $form += ['client_id' => $this->provider->clientId(), 'client_secret' => (string) $this->provider->clientSecret()];
        }

        try {
            $response = $request->post($this->endpoint('token'), $form)->throw()->json();
        } catch (RequestException $e) {
            throw new SignInException(__('identity-providers::messages.identity_provider_did_not_accept'), previous: $e);
        }

        if (empty($response['access_token'])) {
            throw new SignInException(__('identity-providers::messages.identity_provider_did_not_return'));
        }

        return (string) $response['access_token'];
    }

    /** @return array<string, mixed> */
    private function get(string $url, string $token): array
    {
        try {
            return (array) Http::withToken($token)->acceptJson()->timeout(15)->get($url)->throw()->json();
        } catch (RequestException $e) {
            throw new SignInException(__('identity-providers::messages.could_not_read_your_profile'), previous: $e);
        }
    }

    /** @return array{email?: string, email_verified?: bool} */
    private function githubEmail(string $token): array
    {
        $emails = collect($this->get('https://api.github.com/user/emails', $token));
        $primary = $emails->first(fn ($e) => ($e['primary'] ?? false) && ($e['verified'] ?? false))
            ?? $emails->first(fn ($e) => $e['verified'] ?? false);

        return $primary ? ['email' => $primary['email'], 'email_verified' => true] : [];
    }

    private function endpoint(string $name): string
    {
        $configured = $this->provider->configuredEndpoints();

        $url = $configured[$name] ?? match ($this->provider->type()) {
            IdentityProvider::GITHUB => self::GITHUB[$name],
            IdentityProvider::OIDC => $this->discovery()[match ($name) {
                'authorize' => 'authorization_endpoint',
                'token' => 'token_endpoint',
                default => 'userinfo_endpoint',
            }] ?? null,
            default => null,
        };

        if (! $url || ! Str::startsWith($url, 'https://') && ! app()->environment('local', 'testing')) {
            throw new SignInException(__('identity-providers::messages.identity_provider_not_fully_configured', ['endpoint' => $name]));
        }

        return $url;
    }

    /** @return array<string, mixed> OpenID Connect discovery document, cached for an hour */
    private function discovery(): array
    {
        $issuer = rtrim((string) $this->provider->configuredEndpoints()['issuer'], '/');

        if ($issuer === '') {
            throw new SignInException(__('identity-providers::messages.identity_provider_has_no_issuer'));
        }

        return Cache::remember('idp.discovery.'.md5($issuer), 3600, function () use ($issuer) {
            try {
                return (array) Http::acceptJson()->timeout(10)->get($issuer.'/.well-known/openid-configuration')->throw()->json();
            } catch (RequestException $e) {
                throw new SignInException(__('identity-providers::messages.could_not_reach_identity_provider'), previous: $e);
            }
        });
    }
}
