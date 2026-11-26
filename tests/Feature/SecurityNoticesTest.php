<?php

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Notifications\EmailAddressChanged;
use App\Notifications\LoginBlocked;
use App\Notifications\PasswordChanged;
use App\Support\Invitations;
use App\Support\MailQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

describe('password changes', function () {
    it('tells people when they change their password in the profile', function () {
        mailWorks();
        $grace = User::factory()->create();

        $this->actingAs($grace)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($grace, PasswordChanged::class, fn (PasswordChanged $notice, array $channels) => $notice->changedBy === null && $channels === ['database', 'mail']);
    });

    it('tells people when their password is reset with a link', function () {
        mailWorks();
        $grace = User::factory()->create();

        $this->post(route('password.store'), [
            'token' => Password::broker()->createToken($grace),
            'email' => $grace->email,
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($grace, PasswordChanged::class);
    });

    it('does not warn people who choose their first password through an invitation', function () {
        mailWorks();
        $grace = User::factory()->create(['email' => 'grace@example.com']);
        $link = Invitations::link($grace);

        $this->post(route('invitation.store'), [
            'token' => basename(parse_url($link, PHP_URL_PATH)),
            'email' => 'grace@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect(route('dashboard'));

        Notification::assertNotSentTo($grace, PasswordChanged::class);
    });

    it('names the administrator who changed someone\'s password', function () {
        mailWorks();
        $ada = tap(User::factory()->create(['name' => 'Ada']))->assignRole(Role::SuperAdmin->value);
        $grace = User::factory()->create();
        $this->actingAs($ada);

        Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
            ->fillForm(['password' => 'a-new-long-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($grace, PasswordChanged::class, fn (PasswordChanged $notice) => $notice->changedBy === 'Ada');
    });

    it('keeps the notice in the gallery when e-mail is not set up', function () {
        $grace = User::factory()->create();

        $this->actingAs($grace)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ]);

        expect($grace->notifications()->sole()->type)->toBe(PasswordChanged::class);
    });
});

describe('e-mail address changes', function () {
    it('e-mails the old address and tells the account in the gallery', function () {
        mailWorks();
        $grace = User::factory()->create(['email' => 'grace@example.com']);

        $this->actingAs($grace)->patch('/profile', ['name' => $grace->name, 'email' => 'new@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(EmailAddressChanged::class, fn (EmailAddressChanged $notice, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'grace@example.com'
            && $notice->newEmail === 'new@example.com'
            && $channels === ['mail']);
        Notification::assertSentTo($grace, EmailAddressChanged::class, fn (EmailAddressChanged $notice, array $channels) => $channels === ['database']);
    });

    it('stays quiet when the address stays the same', function () {
        mailWorks();
        $grace = User::factory()->create(['email' => 'grace@example.com']);

        $this->actingAs($grace)->patch('/profile', ['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

        Notification::assertNothingSent();
    });
});

describe('blocked logins', function () {
    it('tells people once an hour when their login is blocked after wrong passwords', function () {
        mailWorks();
        $grace = User::factory()->create();
        $attempt = fn () => $this->post('/login', ['email' => $grace->email, 'password' => 'wrong-password']);

        foreach (range(1, 8) as $ignored) {
            $attempt();
        }
        MailQueue::work();
        $this->travel(61)->minutes();
        foreach (range(1, 6) as $ignored) {
            $attempt();
        }
        MailQueue::work();

        Notification::assertSentToTimes($grace, LoginBlocked::class, 2);
    });
});
