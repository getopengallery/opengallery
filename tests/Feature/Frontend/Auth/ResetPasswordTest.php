<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

it('shows the reset form for the link from the e-mail', function () {
    $token = Password::broker()->createToken(User::factory()->create());

    $this->get('/reset-password/'.$token)->assertOk();
});

it('sets the new password with a valid token and sends the person to the login', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        ...frontendPasswordFields(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check('a-long-password', $user->fresh()->password))->toBeTrue();
});

it('answers the same for an unknown address and a wrong link', function (string $email) {
    User::factory()->create(['email' => 'known@example.com']);

    $this->from('/reset-password/x')->post('/reset-password', [
        'token' => 'wrong-token',
        'email' => $email,
        ...frontendPasswordFields(),
    ])->assertSessionHasErrors(['email' => 'This password reset token is invalid.']);
})->with(['known@example.com', 'nobody@example.com']);
