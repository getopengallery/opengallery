<?php

use App\Models\User;
use App\Support\Invitations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

it('lets the invited person choose a password and logs them in', function () {
    $grace = User::factory()->unverified()->create(['email' => 'grace@example.com']);
    $link = Invitations::link($grace);

    $this->get($link)->assertOk()->assertSee('Choose your password');

    parse_str(parse_url($link, PHP_URL_QUERY), $query);
    $this->post(route('invitation.store'), [
        'token' => basename(parse_url($link, PHP_URL_PATH)),
        'email' => $query['email'],
        ...frontendPasswordFields(),
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($grace);
    expect(Hash::check('a-long-password', $grace->fresh()->password))->toBeTrue()
        ->and($grace->fresh()->email_verified_at)->not->toBeNull();
});

it('refuses invitation links after a week, and password reset links', function () {
    $grace = User::factory()->create(['email' => 'grace@example.com']);
    $send = fn (string $token) => $this->post(route('invitation.store'), [
        'token' => $token,
        'email' => 'grace@example.com',
        ...frontendPasswordFields(),
    ]);

    $link = Invitations::link($grace);
    $this->travel(8)->days();
    $send(basename(parse_url($link, PHP_URL_PATH)))
        ->assertSessionHasErrors(['email' => 'This invitation link is no longer valid. Ask for a new one.']);

    $send(Password::broker()->createToken($grace))->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('answers the same for an unknown address and a wrong link', function (string $email) {
    Invitations::link(User::factory()->create(['email' => 'known@example.com']));

    $this->from('/invitation/x')->post('/invitation', [
        'token' => 'wrong-token',
        'email' => $email,
        ...frontendPasswordFields(),
    ])->assertSessionHasErrors(['email' => 'This invitation link is no longer valid. Ask for a new one.']);
})->with(['known@example.com', 'nobody@example.com']);
