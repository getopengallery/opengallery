<?php

namespace App\Extensions;

/**
 * A sign-in method contributed by a plugin (e.g. "Log in with Keycloak").
 */
final readonly class LoginOption
{
    /**
     * @param  string  $key  identifier, also stored as social_accounts.provider
     * @param  string  $label  button text, e.g. "Log in with Keycloak"
     * @param  string  $url  where the button leads (starts the sign-in)
     * @param  string|null  $linkUrl  starts linking this method to the logged-in user
     * @param  bool  $isDefault  "Log in" goes straight here (see LoginOptions::default)
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $label,
        public string $url,
        public ?string $description = null,
        public ?string $iconUrl = null,
        public ?string $linkUrl = null,
        public bool $isDefault = false,
    ) {}
}
