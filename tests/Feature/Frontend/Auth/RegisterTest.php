<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Notifications\SignUpAttempted;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('shows the registration form to guests', function () {
    $this->get('/register')->assertOk();
});

it('signs new users up and logs them in while e-mail does not work', function () {
    $this->post('/register', frontendSignUpFields('test@example.com'))
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs(User::where('email', 'test@example.com')->sole());
});

it('rejects passwords of fewer than 12 characters, as everywhere else', function () {
    $this->post('/register', frontendSignUpFields('test@example.com', frontendPasswordFields('elevenchars')))
        ->assertSessionHasErrors(['password' => 'The password field must be at least 12 characters.']);

    $this->assertGuest();
});

it('closes registration when it is turned off', function () {
    applySiteSettings([SiteSettings::REGISTRATION => false]);

    $this->get('/register')->assertNotFound();
    $this->get(route('login'))->assertDontSee('Create an account');
});

it('tells the people who manage users about a new account only while the setting is on', function (bool $notify) {
    mailWorks();
    config(['gallery.registration_enabled' => true]);
    Setting::put(SiteSettings::NOTIFY_REGISTRATIONS, $notify);
    $admin = userWithRole(Role::Admin);
    $superAdmin = userWithRole(Role::SuperAdmin);
    $moderator = userWithRole(Role::Moderator);

    $this->post('/register', frontendSignUpFields())->assertRedirect(route('login'));

    $grace = User::where('email', 'grace@example.com')->sole();
    if ($notify) {
        Notification::assertSentTo([$admin, $superAdmin], AccountCreated::class, fn (AccountCreated $mail) => $mail->account->is($grace));
        Notification::assertNotSentTo([$moderator, $grace], AccountCreated::class);
    } else {
        Notification::assertNotSentTo([$admin, $superAdmin, $moderator], AccountCreated::class);
    }
})->with(['on' => true, 'off' => false]);

describe('with a taken address', function () {
    it('answers the same as for a new address and tells the owner once', function () {
        config(['mail.default' => 'smtp', 'gallery.registration_enabled' => true]);
        Notification::fake();
        $owner = User::factory()->create(['email' => 'taken@example.com', 'name' => 'Owner']);
        $signUp = fn (string $email) => $this->post('/register', frontendSignUpFields($email, ['name' => 'Someone']));

        $new = $signUp('new@example.com')->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $status = $new->getSession()->get('status');
        $signUp('taken@example.com')->assertRedirect(route('login'))->assertSessionHasNoErrors()->assertSessionHas('status', $status);
        $signUp('taken@example.com');

        $this->assertGuest();
        expect($owner->fresh()->name)->toBe('Owner')
            ->and(Hash::check('a-long-password', $owner->fresh()->password))->toBeFalse()
            ->and(User::where('email', 'taken@example.com')->count())->toBe(1);
        Notification::assertSentToTimes($owner, SignUpAttempted::class, 1);
    });

    it('reports the address as taken while no confirmation e-mail can be sent', function () {
        config(['gallery.registration_enabled' => true]);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', frontendSignUpFields('taken@example.com', ['name' => 'Someone']))
            ->assertSessionHasErrors('email');
    });
});
