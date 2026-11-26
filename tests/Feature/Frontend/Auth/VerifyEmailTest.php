<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

it('shows the confirmation notice to people who have not confirmed their address', function () {
    $this->actingAs(User::factory()->unverified()->create())->get('/verify-email')->assertOk();
});

describe('the confirmation link', function () {
    it('confirms the address and opens the dashboard', function () {
        $user = User::factory()->unverified()->create();
        Event::fake();

        $this->actingAs($user)
            ->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]))
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

        Event::assertDispatched(Verified::class);
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('does not confirm the address with an invalid hash', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1('wrong-email')]));

        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });
});

describe('while e-mail works', function () {
    beforeEach(function () {
        config(['mail.default' => 'smtp', 'gallery.registration_enabled' => true]);
        Notification::fake();
    });

    it('asks people who sign up to confirm their address before they use the gallery', function () {
        $grace = signUp();

        Notification::assertSentTo($grace, VerifyEmail::class);
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->get(route('home'))->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk();
        $this->get(route('profile.edit'))->assertOk();

        confirmEmail($grace);

        $this->get(route('dashboard'))->assertOk();
    });

    it('holds the admin area back too', function () {
        $this->actingAs(userWithRole(Role::Admin, ['email_verified_at' => null]))
            ->get('/admin')
            ->assertRedirect(route('verification.notice'));
    });
});

it('holds nobody back while e-mail does not work', function () {
    config(['mail.default' => 'log']);

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertOk();
});
