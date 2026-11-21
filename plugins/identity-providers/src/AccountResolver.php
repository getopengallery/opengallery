<?php

namespace Plugins\IdentityProviders;

use App\Models\Group;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies a provider's rules to the claims of a successful sign-in:
 * find the linked account, optionally link by verified e-mail or create a
 * new account, then sync groups.
 *
 * An existing account is never taken over just because the e-mail matches,
 * unless the provider is explicitly configured to do so and confirms the
 * address is verified.
 */
class AccountResolver
{
    public function __construct(private IdentityProvider $provider) {}

    /**
     * @param  array<string, mixed>  $claims
     * @param  User|null  $current  set when linking from the profile page
     */
    public function resolve(array $claims, ?User $current = null): User
    {
        $identifier = $this->value($claims, 'identifier');

        if ($identifier === null) {
            throw new SignInException(__('identity-providers::messages.provider_did_not_send_claim', [
                'provider' => $this->provider->name(), 'claim' => $this->provider->claim('identifier'),
            ]));
        }

        $account = SocialAccount::where('provider', $this->provider->key())->where('provider_user_id', $identifier)->first();
        $email = $this->value($claims, 'email');

        if ($current) {
            if ($account && $account->user_id !== $current->id) {
                throw new SignInException(__('identity-providers::messages.this_provider_account_already_linked', ['provider' => $this->provider->name()]));
            }
            $user = $current;
        } elseif ($account) {
            $user = $account->user;
        } elseif ($this->provider->linksByEmail() && $email && $this->emailVerified($claims) && ($existing = User::where('email', $email)->first())) {
            $user = $existing;
        } else {
            $user = $this->createUser($claims, $email);
        }

        $this->storeAccount($user, $identifier, $claims, $account);

        if ($this->provider->syncsGroups()) {
            $this->syncGroups($user, $claims);
        }

        return $user;
    }

    /** @param array<string, mixed> $claims */
    private function createUser(array $claims, ?string $email): User
    {
        $provider = $this->provider->name();

        if (! $this->provider->createsUsers()) {
            throw new SignInException(__('identity-providers::messages.there_no_account_for_you', ['provider' => $provider]));
        }

        if (! $email) {
            throw new SignInException(__('identity-providers::messages.provider_did_not_share_mail', ['provider' => $provider]));
        }

        if (User::where('email', $email)->exists()) {
            throw new SignInException(__('identity-providers::messages.account_with_this_mail_already', ['provider' => $provider]));
        }

        $user = DB::transaction(function () use ($claims, $email) {
            $user = User::create([
                'name' => $this->value($claims, 'name') ?? $this->value($claims, 'username') ?? Str::before($email, '@'),
                'email' => $email,
                'password' => Str::password(32), // unusable until reset; sign-in happens through the provider
            ]);

            if ($this->emailVerified($claims)) {
                $user->forceFill(['email_verified_at' => now()])->saveQuietly();
            }

            return $user;
        });

        event(new Registered($user));

        return $user;
    }

    /** @param array<string, mixed> $claims */
    private function storeAccount(User $user, string $identifier, array $claims, ?SocialAccount $account): void
    {
        $account ??= new SocialAccount(['provider' => $this->provider->key(), 'provider_user_id' => $identifier]);
        $account->user()->associate($user);
        $account->fill([
            'email' => $this->value($claims, 'email') ?? $this->value($claims, 'username'),
            'avatar' => is_string($claims['picture'] ?? $claims['avatar_url'] ?? null) ? ($claims['picture'] ?? $claims['avatar_url']) : null,
        ])->save();
    }

    /**
     * Mapped mode: membership of every mapped group follows the claim
     * (added and removed). By-name mode: groups whose name matches a claim
     * value are added (optionally created); nothing is removed.
     *
     * @param  array<string, mixed>  $claims
     */
    private function syncGroups(User $user, array $claims): void
    {
        $values = $this->groupValues($claims);

        if ($this->provider->groupMode() === IdentityProvider::MODE_MAPPED) {
            $map = collect($this->provider->groupMap());
            $managed = $map->pluck('group_id')->unique();
            $wanted = $map->filter(fn ($row) => in_array(Str::lower(ltrim(trim($row['value']), '/')), $values, true))->pluck('group_id')->unique();
            $existing = Group::whereKey($managed)->pluck('id');
            $current = $user->groups()->whereKey($existing)->pluck('groups.id');

            $user->groups()->attach($wanted->intersect($existing)->diff($current)->values()->all());
            $user->groups()->detach($current->diff($wanted)->values()->all());

            return;
        }

        $names = collect($this->rawGroupValues($claims));
        $groups = $names->map(function (string $name) {
            $group = Group::whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();

            return $group ?? ($this->provider->createsMissingGroups() ? Group::create(['name' => $name]) : null);
        })->filter();

        $user->groups()->syncWithoutDetaching($groups->pluck('id')->unique()->values()->all());
    }

    /** @return list<string> lower-cased group values from the claim */
    private function groupValues(array $claims): array
    {
        return array_map(Str::lower(...), $this->rawGroupValues($claims));
    }

    /** @return list<string> */
    private function rawGroupValues(array $claims): array
    {
        $raw = data_get($claims, $this->provider->claim('groups'), []);

        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }

        return collect((array) $raw)
            ->map(fn ($value) => is_array($value) ? ($value['name'] ?? $value['displayName'] ?? null) : $value)
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value) => ltrim(trim((string) $value), '/')) // Keycloak sends "/Family"
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $claims */
    private function value(array $claims, string $claim): ?string
    {
        $name = $this->provider->claim($claim);
        $value = $name === '' ? null : data_get($claims, $name);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @param array<string, mixed> $claims */
    private function emailVerified(array $claims): bool
    {
        $value = data_get($claims, $this->provider->claim('email_verified'));

        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }
}
