<?php

use App\Enums\Role;
use App\Extensions\PluginSettings;
use App\Filament\Pages\Plugins;
use App\Models\Group;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Plugins\IdentityProviders\Filament\Settings;

describe('login page', function () {
    it('shows a button for each enabled provider', function () {
        storeIdentityProvider(['name' => '<b>Family</b> ID']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Log in with &lt;b&gt;Family&lt;/b&gt; ID', false)
            ->assertSee('For relatives')
            ->assertSee(route('idp.redirect', 'family'));
    });

    it('hides providers that are turned off or hidden from the login page', function (array $overrides) {
        storeIdentityProvider($overrides);

        $this->get(route('login'))->assertDontSee('Log in with Family ID');
    })->with([[['enabled' => false]], [['show_on_login' => false]]]);

    it('goes straight to the default provider, keeping the password form at ?local=1', function () {
        storeIdentityProvider(['is_default' => true]);

        $this->get(route('login'))->assertRedirect(route('idp.redirect', 'family'));
        $this->get(route('login', ['local' => 1]))->assertOk()->assertSee('Password');
    });
});

describe('signing in', function () {
    it('creates an account on first sign-in using PKCE and the stored secret', function () {
        storeIdentityProvider();
        fakeIdentityProvider(annaClaims());

        $redirect = $this->get(route('idp.redirect', 'family'));
        expect($redirect->headers->get('Location'))
            ->toStartWith(IDP_ISSUER.'/auth?')
            ->toContain('code_challenge_method=S256', 'client_id=gallery', 'scope=openid+profile+email');

        signInWithIdentityProvider()->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'anna@example.com')->sole();
        expect($user->name)->toBe('Anna Weber')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->hasRole(Role::User->value))->toBeTrue()
            ->and(SocialAccount::sole()->only('provider', 'provider_user_id'))->toBe(['provider' => 'family', 'provider_user_id' => 'uuid-anna']);
        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn ($request) => $request->url() === IDP_ISSUER.'/token'
            && filled($request['code_verifier']) && $request['client_secret'] === 's3cret' && $request['code'] === 'the-code');
    });

    it('recognises returning people by the configured identifier claim', function () {
        storeIdentityProvider(['claims' => ['identifier' => 'preferred_username']]);
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'anna']);
        fakeIdentityProvider(annaClaims(['sub' => 'changed-uuid']));

        signInWithIdentityProvider();

        $this->assertAuthenticatedAs($user);
    });

    it('refuses unknown people when account creation is off', function () {
        storeIdentityProvider(['create_users' => false]);
        fakeIdentityProvider(annaClaims());

        signInWithIdentityProvider()->assertRedirect(route('login', ['local' => 1]))->assertSessionHasErrors(['sign_in' => 'There is no account for you yet. Ask an administrator to create one, then link Family ID from your profile.']);

        $this->assertGuest();
    });

    it('never takes over an existing account by e-mail unless allowed and verified', function (array $overrides, array $claims, bool $connected) {
        storeIdentityProvider($overrides);
        $existing = User::factory()->create(['email' => 'anna@example.com']);
        fakeIdentityProvider(annaClaims($claims));

        signInWithIdentityProvider();

        $connected ? $this->assertAuthenticatedAs($existing) : $this->assertGuest();
        expect(User::count())->toBe(1);
    })->with([
        'linking off' => [[], [], false],
        'linking on, verified' => [['link_by_email' => true], [], true],
        'linking on, unverified' => [['link_by_email' => true], ['email_verified' => false], false],
    ]);

    it('rejects a callback whose state does not match', function () {
        storeIdentityProvider();
        fakeIdentityProvider(annaClaims());

        $this->get(route('idp.callback', ['provider' => 'family', 'code' => 'x', 'state' => 'forged']))
            ->assertSessionHasErrors('sign_in');

        $this->assertGuest();
        Http::assertNothingSent();
    });

    it('reads the verified e-mail from GitHub when the profile hides it', function () {
        storeIdentityProvider(['type' => 'github', 'issuer' => null]);
        Http::preventStrayRequests();
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gh']),
            'api.github.com/user' => Http::response(['id' => 42, 'login' => 'octo', 'name' => null, 'email' => null]),
            'api.github.com/user/emails' => Http::response([['email' => 'octo@example.com', 'primary' => true, 'verified' => true]]),
        ]);

        signInWithIdentityProvider();

        expect(User::sole()->only('name', 'email'))->toBe(['name' => 'octo', 'email' => 'octo@example.com']);
    });
});

describe('account approval', function () {
    beforeEach(function () {
        Setting::put(SiteSettings::REQUIRE_APPROVAL, true);
        Notification::fake();
    });

    it('holds new accounts for approval unless the provider approves them', function () {
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
        storeIdentityProvider();
        fakeIdentityProvider(annaClaims());

        signInWithIdentityProvider();

        expect(User::firstWhere('email', 'anna@example.com')->awaiting_approval)->toBeTrue();
        $this->get(route('dashboard'))->assertRedirect(route('approval.notice'));
        Notification::assertSentTo($admin, AccountCreated::class);
    });

    it('lets new accounts in without an approval notice when the provider approves them', function () {
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
        storeIdentityProvider(['approve_new_accounts' => true]);
        fakeIdentityProvider(annaClaims());

        signInWithIdentityProvider();

        expect(User::firstWhere('email', 'anna@example.com')->awaiting_approval)->toBeFalse();
        $this->get(route('dashboard'))->assertOk();
        Notification::assertNotSentTo($admin, AccountCreated::class);
    });

    it('keeps an existing account on hold when the provider approves new accounts', function () {
        storeIdentityProvider(['approve_new_accounts' => true]);
        $user = User::factory()->create(['email' => 'anna@example.com', 'awaiting_approval' => true]);
        $user->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'uuid-anna']);
        fakeIdentityProvider(annaClaims());

        signInWithIdentityProvider();

        expect($user->fresh()->awaiting_approval)->toBeTrue();
    });
});

describe('group sync', function () {
    it('makes mapped groups follow the provider and leaves other groups alone', function () {
        [$family, $leaders, $club] = Group::factory()->count(3)->sequence(['name' => 'Family'], ['name' => 'Leaders'], ['name' => 'Club'])->create();
        storeIdentityProvider(['sync_groups' => true, 'group_mode' => 'mapped', 'group_map' => [
            ['value' => '/family', 'group_id' => $family->id],
            ['value' => 'leaders', 'group_id' => $leaders->id],
        ]]);
        $user = User::factory()->create(['email' => 'anna@example.com']);
        $user->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'uuid-anna']);
        $user->groups()->attach([$leaders->id, $club->id]);
        fakeIdentityProvider(annaClaims(['groups' => ['/Family', '/other']]));

        signInWithIdentityProvider();

        expect($user->groups()->pluck('name')->sort()->values()->all())->toBe(['Club', 'Family']);
    });

    it('adds groups by name and can create missing ones', function () {
        Group::factory()->create(['name' => 'Family']);
        storeIdentityProvider(['sync_groups' => true, 'group_mode' => 'by_name', 'create_missing_groups' => true]);
        fakeIdentityProvider(annaClaims(['groups' => 'family, Scouts']));

        signInWithIdentityProvider();

        expect(User::sole()->groups()->pluck('name')->sort()->values()->all())->toBe(['Family', 'Scouts'])
            ->and(Group::count())->toBe(2);
    });

    it('keeps unlisted groups in by-name mode when removal is off', function () {
        [$family, $club] = Group::factory()->count(2)->sequence(['name' => 'Family'], ['name' => 'Club'])->create();
        storeIdentityProvider(['sync_groups' => true, 'group_mode' => 'by_name']);
        $user = User::factory()->create(['email' => 'anna@example.com']);
        $user->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'uuid-anna']);
        $user->groups()->attach($club);
        fakeIdentityProvider(annaClaims(['groups' => ['Family']]));

        signInWithIdentityProvider();

        expect($user->groups()->pluck('name')->sort()->values()->all())->toBe(['Club', 'Family']);
    });

    it('removes unlisted groups in by-name mode when removal is on', function () {
        [$family, $club] = Group::factory()->count(2)->sequence(['name' => 'Family'], ['name' => 'Club'])->create();
        storeIdentityProvider(['sync_groups' => true, 'group_mode' => 'by_name', 'remove_unlisted_groups' => true]);
        $user = User::factory()->create(['email' => 'anna@example.com']);
        $user->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'uuid-anna']);
        $user->groups()->attach($club);
        fakeIdentityProvider(annaClaims(['groups' => ['Family']]));

        signInWithIdentityProvider();

        expect($user->groups()->pluck('name')->all())->toBe(['Family']);
    });

    it('removes every group when the provider sends an empty list and removal is on', function () {
        $club = Group::factory()->create(['name' => 'Club']);
        storeIdentityProvider(['sync_groups' => true, 'group_mode' => 'by_name', 'remove_unlisted_groups' => true]);
        $user = User::factory()->create(['email' => 'anna@example.com']);
        $user->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'uuid-anna']);
        $user->groups()->attach($club);
        fakeIdentityProvider(annaClaims(['groups' => []]));

        signInWithIdentityProvider();

        expect($user->groups()->count())->toBe(0);
    });

    it('keeps all groups when the provider sends no groups claim and removal is on', function () {
        $club = Group::factory()->create(['name' => 'Club']);
        storeIdentityProvider(['sync_groups' => true, 'group_mode' => 'by_name', 'remove_unlisted_groups' => true]);
        $user = User::factory()->create(['email' => 'anna@example.com']);
        $user->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'uuid-anna']);
        $user->groups()->attach($club);
        fakeIdentityProvider(annaClaims());

        signInWithIdentityProvider();

        expect($user->groups()->pluck('name')->all())->toBe(['Club']);
    });
});

describe('linking from the profile', function () {
    it('links the provider to the logged-in account', function () {
        storeIdentityProvider(['create_users' => false]);
        $user = User::factory()->create(['email' => 'someone-else@example.com']);
        fakeIdentityProvider(annaClaims());

        $this->actingAs($user);
        signInWithIdentityProvider('idp.link')->assertRedirect(route('profile.edit'));

        expect($user->socialAccounts()->sole()->provider_user_id)->toBe('uuid-anna');
        $this->get(route('profile.edit'))->assertSee('Family ID')->assertSee('Unlink');
    });

    it('refuses a provider account that belongs to someone else', function () {
        storeIdentityProvider();
        User::factory()->create()->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'uuid-anna']);
        fakeIdentityProvider(annaClaims());

        $this->actingAs(User::factory()->create());
        signInWithIdentityProvider('idp.link')->assertRedirect(route('profile.edit'))->assertSessionHasErrors('sign_in');
    });

    it('unlinks a provider', function () {
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'family', 'provider_user_id' => 'x']);

        $this->actingAs($user)->delete(route('linked-accounts.destroy', 'family'))->assertRedirect(route('profile.edit'));

        expect($user->socialAccounts()->count())->toBe(0);
    });
});

describe('admin', function () {
    beforeEach(fn () => $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value)));

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

    it('links the plugin card to its settings and filters plugins by state', function () {
        Livewire::test(Plugins::class)
            ->assertSee(Settings::getUrl())
            ->assertSee('Enabled in the server configuration')
            ->filterTable('status', 'inactive')
            ->assertDontSee('Identity providers')
            ->assertSee('Example Plugin');
    });

    it('keeps plugin settings away from staff who cannot manage plugins', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value))
            ->get(Settings::getUrl())
            ->assertForbidden();
    });
});
