<?php

namespace Plugins\IdentityProviders;

use App\Extensions\PluginSettings;

/**
 * One configured identity provider (read from the plugin settings).
 */
final readonly class IdentityProvider
{
    public const OIDC = 'oidc';

    public const OAUTH2 = 'oauth2';

    public const GITHUB = 'github';

    public const MODE_MAPPED = 'mapped';

    public const MODE_BY_NAME = 'by_name';

    /** Claim defaults per provider type. */
    private const CLAIM_DEFAULTS = [
        self::OIDC => ['identifier' => 'sub', 'name' => 'name', 'email' => 'email', 'username' => 'preferred_username', 'email_verified' => 'email_verified', 'groups' => 'groups'],
        self::OAUTH2 => ['identifier' => 'id', 'name' => 'name', 'email' => 'email', 'username' => 'username', 'email_verified' => 'email_verified', 'groups' => 'groups'],
        self::GITHUB => ['identifier' => 'id', 'name' => 'name', 'email' => 'email', 'username' => 'login', 'email_verified' => 'email_verified', 'groups' => ''],
    ];

    /** @param array<string, mixed> $config raw settings entry */
    public function __construct(public array $config) {}

    public function key(): string
    {
        return (string) $this->config['key'];
    }

    public function name(): string
    {
        return (string) ($this->config['name'] ?? $this->key());
    }

    public function description(): ?string
    {
        return ($this->config['description'] ?? null) ?: null;
    }

    public function type(): string
    {
        return in_array($this->config['type'] ?? null, [self::OAUTH2, self::GITHUB], true) ? $this->config['type'] : self::OIDC;
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) && filled($this->config['client_id'] ?? null);
    }

    public function showOnLogin(): bool
    {
        return (bool) ($this->config['show_on_login'] ?? true);
    }

    public function isDefault(): bool
    {
        return (bool) ($this->config['is_default'] ?? false);
    }

    public function iconPath(): ?string
    {
        return ($this->config['icon'] ?? null) ?: null;
    }

    public function clientId(): string
    {
        return (string) ($this->config['client_id'] ?? '');
    }

    public function clientSecret(): ?string
    {
        return PluginSettings::decrypt($this->config['client_secret'] ?? null);
    }

    public function scopes(): string
    {
        $default = match ($this->type()) {
            self::GITHUB => 'read:user user:email',
            self::OAUTH2 => '',
            default => 'openid profile email',
        };

        return trim((string) (($this->config['scopes'] ?? null) ?: $default));
    }

    /** Client authentication at the token endpoint: "post" (body) or "basic" (header). */
    public function tokenAuth(): string
    {
        return ($this->config['token_auth'] ?? 'post') === 'basic' ? 'basic' : 'post';
    }

    public function claim(string $name): string
    {
        return trim((string) (($this->config['claims'][$name] ?? null) ?: self::CLAIM_DEFAULTS[$this->type()][$name]));
    }

    public function createsUsers(): bool
    {
        return (bool) ($this->config['create_users'] ?? false);
    }

    public function approvesNewAccounts(): bool
    {
        return (bool) ($this->config['approve_new_accounts'] ?? false);
    }

    public function linksByEmail(): bool
    {
        return (bool) ($this->config['link_by_email'] ?? false);
    }

    public function syncsGroups(): bool
    {
        return (bool) ($this->config['sync_groups'] ?? false) && $this->claim('groups') !== '';
    }

    public function groupMode(): string
    {
        return ($this->config['group_mode'] ?? null) === self::MODE_BY_NAME ? self::MODE_BY_NAME : self::MODE_MAPPED;
    }

    /** @return list<array{value: string, group_id: int}> */
    public function groupMap(): array
    {
        return collect($this->config['group_map'] ?? [])
            ->filter(fn ($row) => filled($row['value'] ?? null) && filled($row['group_id'] ?? null))
            ->map(fn ($row) => ['value' => (string) $row['value'], 'group_id' => (int) $row['group_id']])
            ->values()
            ->all();
    }

    public function createsMissingGroups(): bool
    {
        return (bool) ($this->config['create_missing_groups'] ?? false);
    }

    public function removesUnlistedGroups(): bool
    {
        return (bool) ($this->config['remove_unlisted_groups'] ?? false);
    }

    /** @return array{issuer: ?string, authorize: ?string, token: ?string, userinfo: ?string} */
    public function configuredEndpoints(): array
    {
        return [
            'issuer' => ($this->config['issuer'] ?? null) ?: null,
            'authorize' => ($this->config['authorize_url'] ?? null) ?: null,
            'token' => ($this->config['token_url'] ?? null) ?: null,
            'userinfo' => ($this->config['userinfo_url'] ?? null) ?: null,
        ];
    }

    public function callbackUrl(): string
    {
        return route('idp.callback', $this->key());
    }
}
