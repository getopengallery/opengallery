<?php

use App\Enums\Role;
use App\Models\User;

it('offers the admin under Profile in the account menu, not among the header links', function () {
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);

    $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

    // Desktop account menu and mobile menu: Profile, then Admin, then Log out.
    expect(substr_count($html, '>Admin</a>'))->toBe(2)
        ->and(strpos($html, '>Profile</a>'))->toBeLessThan(strpos($html, '>Admin</a>'))
        ->and(strpos($html, '>Admin</a>'))->toBeLessThan(strpos($html, 'Log out'));
});

it('does not offer the admin to members who may not open it', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::User->value))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Profile')
        ->assertDontSee(url('/admin'), false);
});
