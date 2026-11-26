<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\AccessSettings;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Album;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Notifications\AlbumShared;
use App\Notifications\UserInvited;
use App\Support\Invitations;
use App\Support\Mailing;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

describe('sending', function () {
    it('only counts mail that leaves the server as delivered', function () {
        config(['mail.default' => 'log']);
        expect(Mailing::delivers())->toBeFalse();

        config(['mail.default' => 'smtp']);
        expect(Mailing::delivers())->toBeTrue();
    });

    it('reports a failing mail server instead of throwing', function () {
        expect(Mailing::send(fn () => throw new RuntimeException('Connection refused')))->toBeFalse()
            ->and(Mailing::send(fn () => null))->toBeTrue();
    });
});

describe('invitations', function () {
    it('creates a user without a password and e-mails them an invitation', function () {
        mailWorks();
        actingAsRole(attributes: ['name' => 'Ada']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Invitation sent to grace@example.com');

        $grace = User::where('email', 'grace@example.com')->sole();
        Notification::assertSentTo($grace, UserInvited::class, fn (UserInvited $mail) => str_contains($mail->url, '/invitation/') && $mail->invitedBy === 'Ada');
    });

    it('gives the admin the link to pass on when e-mail is not set up', function () {
        config(['mail.default' => 'log']);
        Notification::fake();
        actingAsRole(attributes: ['name' => 'Ada']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('The invitation was not e-mailed');

        Notification::assertNothingSent();
    });

    it('asks for a password when no invitation is sent', function () {
        mailWorks();
        actingAsRole(attributes: ['name' => 'Ada']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => false])
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);
    });

    it('lets the invited person choose a password and logs them in', function () {
        $grace = User::factory()->unverified()->create(['email' => 'grace@example.com']);
        $link = Invitations::link($grace);

        $this->get($link)->assertOk()->assertSee('Choose your password');

        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $this->post(route('invitation.store'), [
            'token' => basename(parse_url($link, PHP_URL_PATH)),
            'email' => $query['email'],
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
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
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ]);

        $link = Invitations::link($grace);
        $this->travel(8)->days();
        $send(basename(parse_url($link, PHP_URL_PATH)))
            ->assertSessionHasErrors(['email' => 'This invitation link is no longer valid. Ask for a new one.']);

        $send(Password::broker()->createToken($grace))->assertSessionHasErrors('email');
        $this->assertGuest();
    });

    it('sends a new password link from the user page', function () {
        mailWorks();
        actingAsRole(attributes: ['name' => 'Ada']);
        $grace = User::factory()->create(['email' => 'grace@example.com']);

        Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
            ->callAction('sendInvitation')
            ->assertNotified('Invitation sent to grace@example.com');

        Notification::assertSentTo($grace, UserInvited::class);
    });
});

describe('share notices', function () {
    it('tells people who get access to a restricted album, directly or through a group', function () {
        mailWorks();
        $ada = actingAsRole(attributes: ['name' => 'Ada']);
        [$direct, $member, $already] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $group = Group::factory()->create();
        $group->users()->attach([$member->id, $ada->id]);
        $album = Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Camp']);
        $album->sharedUsers()->attach($already);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm([
                'shared_with' => ['user:'.$already->id, 'user:'.$direct->id, 'group:'.$group->id],
                'notify_shared' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('2 people will get an e-mail shortly');

        Notification::assertSentTo([$direct, $member], AlbumShared::class, fn (AlbumShared $mail) => $mail->album->is($album) && $mail->sharedBy === 'Ada');
        Notification::assertNotSentTo([$already, $ada], AlbumShared::class);
    });

    it('stays quiet when the switch is off or the album is not restricted', function () {
        mailWorks();
        actingAsRole(attributes: ['name' => 'Ada']);
        $person = User::factory()->create();

        $restricted = Album::factory()->visibility(Visibility::Restricted)->create();
        Livewire::test(EditAlbum::class, ['record' => $restricted->getRouteKey()])
            ->fillForm(['shared_with' => ['user:'.$person->id], 'notify_shared' => false])
            ->call('save');

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Open day', 'visibility' => Visibility::Public, 'notify_shared' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Notification::assertNothingSent();
    });

    it('tells people when a new album is created already shared with them', function () {
        mailWorks();
        actingAsRole(attributes: ['name' => 'Ada']);
        $person = User::factory()->create();

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Camp', 'visibility' => Visibility::Restricted, 'shared_with' => ['user:'.$person->id], 'notify_shared' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($person, AlbumShared::class);
    });
});

describe('sign-up notices', function () {
    it('tells the people who manage users about a new account when the setting is on', function () {
        mailWorks();
        config(['gallery.registration_enabled' => true]);
        Setting::put(SiteSettings::NOTIFY_REGISTRATIONS, true);
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
        $superAdmin = tap(User::factory()->create())->assignRole(Role::SuperAdmin->value);
        $moderator = tap(User::factory()->create())->assignRole(Role::Moderator->value);

        $this->post('/register', [
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect(route('login'));

        $grace = User::where('email', 'grace@example.com')->sole();
        Notification::assertSentTo([$admin, $superAdmin], AccountCreated::class, fn (AccountCreated $mail) => $mail->account->is($grace));
        Notification::assertNotSentTo([$moderator, $grace], AccountCreated::class);
    });

    it('sends nothing about new accounts while the setting is off', function () {
        mailWorks();
        config(['gallery.registration_enabled' => true]);
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);

        $this->post('/register', [
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ]);

        Notification::assertNotSentTo($admin, AccountCreated::class);
    });

    it('turns the notice on in the site settings', function () {
        actingAsRole(attributes: ['name' => 'Ada']);

        Livewire::test(AccessSettings::class)
            ->fillForm(['registration' => true, 'notify_registrations' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(SiteSettings::notifiesAboutRegistrations())->toBeTrue();
    });
});
