<?php

namespace Plugins\IdentityProviders;

use App\Extensions\LoginOption;
use App\Extensions\PluginSettings;
use Illuminate\Support\Collection;

/**
 * Reads and writes the configured providers (plugin setting "providers").
 */
class ProviderRepository
{
    public const PLUGIN = 'identity-providers';

    /** @return Collection<string, IdentityProvider> */
    public function all(): Collection
    {
        return collect((array) PluginSettings::get(self::PLUGIN, 'providers', []))
            ->filter(fn ($config) => is_array($config) && filled($config['key'] ?? null))
            ->map(fn (array $config) => new IdentityProvider($config))
            ->keyBy(fn (IdentityProvider $provider) => $provider->key());
    }

    /** An enabled provider, or null. */
    public function find(string $key): ?IdentityProvider
    {
        $provider = $this->all()->get($key);

        return $provider?->isEnabled() ? $provider : null;
    }

    /** @param list<array<string, mixed>> $configs */
    public function save(array $configs): void
    {
        PluginSettings::put(self::PLUGIN, 'providers', array_values($configs));
    }

    /** @return list<LoginOption> */
    public function loginOptions(): array
    {
        return $this->all()
            ->filter(fn (IdentityProvider $p) => $p->isEnabled() && $p->showOnLogin())
            ->map(fn (IdentityProvider $p) => new LoginOption(
                key: $p->key(),
                name: $p->name(),
                label: __('identity-providers::messages.log_in_with_name', ['name' => $p->name()]),
                url: route('idp.redirect', $p->key()),
                description: $p->description(),
                iconUrl: $p->iconPath() ? route('idp.icon', $p->key()) : null,
                linkUrl: route('idp.link', $p->key()),
                isDefault: $p->isDefault(),
            ))
            ->values()
            ->all();
    }
}
