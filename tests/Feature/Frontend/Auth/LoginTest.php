<?php

use App\Models\User;

describe('the login page', function () {
    it('shows the login form to guests', function () {
        $this->get(route('login'))->assertOk()->assertSee('Password');
    });

    it('shows a button for each enabled provider, with the name escaped', function () {
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
    })->with(['turned off' => [['enabled' => false]], 'hidden from the login page' => [['show_on_login' => false]]]);

    it('goes straight to the default provider, keeping the password form at ?local=1', function () {
        storeIdentityProvider(['is_default' => true]);

        $this->get(route('login'))->assertRedirect(route('idp.redirect', 'family'));
        $this->get(route('login', ['local' => 1]))->assertOk()->assertSee('Password');
    });
});

describe('logging in', function () {
    it('logs in with the right password and opens the dashboard', function () {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    });

    it('keeps people with the wrong password logged out', function () {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    it('logs out and returns to the start page', function () {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    });
});
