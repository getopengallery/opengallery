<?php

use App\Models\User;

it('shows the confirmation form to logged-in people', function () {
    $this->actingAs(User::factory()->create())->get('/confirm-password')->assertOk();
});

it('confirms the password when it is right', function () {
    $this->actingAs(User::factory()->create())
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('refuses a wrong password', function () {
    $this->actingAs(User::factory()->create())
        ->post('/confirm-password', ['password' => 'wrong-password'])
        ->assertSessionHasErrors();
});
