<?php

use App\Enums\ActivityLevel;
use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\SessionsSignedOut;
use App\Support\Avatars;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

describe('the profile page', function () {
    it('shows the person\'s albums in the deletion dialog', function () {
        $user = User::factory()->create();
        Album::factory()->for($user, 'owner')->create(['title' => 'Summer camp']);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Summer camp')
            ->assertSee('Delete my albums and photos with my account');
    });

    it('lets guests log in first', function () {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    });
});

describe('saving the profile details', function () {
    it('saves the name and a changed e-mail address, and asks to confirm the new address again', function () {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Test User', 'email' => 'test@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();
        expect($user->name)->toBe('Test User')
            ->and($user->email)->toBe('test@example.com')
            ->and($user->email_verified_at)->toBeNull();
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    });

    it('keeps the address confirmed when the e-mail address is unchanged', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Test User', 'email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        expect($user->refresh()->email_verified_at)->not->toBeNull();
    });

    it('needs the current password to change the e-mail address', function (array $input) {
        $user = User::factory()->create(['email' => 'grace@example.com']);

        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => 'new@example.com', ...$input])
            ->assertSessionHasErrors('current_password');

        expect($user->fresh()->email)->toBe('grace@example.com');
    })->with(['no password' => [[]], 'a wrong password' => [['current_password' => 'wrong-password']]]);

    it('does not limit saving a profile without a password', function () {
        $user = User::factory()->create();

        foreach (range(1, 8) as $attempt) {
            $this->actingAs($user)->patch('/profile', ['name' => "Name {$attempt}", 'email' => $user->email])
                ->assertRedirect('/profile');
        }
    });

    it('lets people choose their language, or go back to the site default', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'de'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        expect($user->refresh()->locale)->toBe('de');

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => ''])
            ->assertSessionHasNoErrors();

        expect($user->refresh()->locale)->toBeNull();
    });

    it('rejects a language core has no translation for', function () {
        $user = User::factory()->create(['locale' => 'de']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'xx'])
            ->assertSessionHasErrors('locale');

        expect($user->refresh()->locale)->toBe('de');
    });
});

describe('changing the password', function () {
    it('saves the new password', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', ['current_password' => 'password', ...frontendPasswordFields('new-password')])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
    });

    it('needs the current password', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', ['current_password' => 'wrong-password', ...frontendPasswordFields('new-password')])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });

    it('needs at least 12 characters', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', ['current_password' => 'password', ...frontendPasswordFields('elevenchars')])
            ->assertSessionHasErrorsIn('updatePassword', 'password');

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });
});

describe('the profile picture', function () {
    beforeEach(fn () => Storage::fake(Avatars::DISK));

    it('is re-encoded as a square when uploaded', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.jpg', 800, 400)])
            ->assertRedirect(route('profile.edit'));

        $path = $user->fresh()->avatar_path;
        expect($path)->toStartWith('avatars/')->toEndWith('.webp');
        Storage::disk(Avatars::DISK)->assertExists($path);
        [$width, $height] = getimagesize(Storage::disk(Avatars::DISK)->path($path));
        expect([$width, $height])->toBe([Avatars::SIZE, Avatars::SIZE]);
    });

    it('rejects files that are not raster images', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->createWithContent('me.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
            ->assertSessionHasErrorsIn('avatar', 'avatar');

        expect($user->fresh()->avatar_path)->toBeNull();
    });

    it('deletes the old file when the picture is replaced or removed', function () {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('a.jpg')]);
        $first = $user->fresh()->avatar_path;

        $this->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('b.jpg')]);
        $second = $user->fresh()->avatar_path;
        Storage::disk(Avatars::DISK)->assertMissing($first);

        $this->delete(route('profile.avatar.destroy'))->assertRedirect(route('profile.edit'));
        expect($user->fresh()->avatar_path)->toBeNull();
        Storage::disk(Avatars::DISK)->assertMissing($second);
    });
});

describe('linked sign-in accounts', function () {
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

describe('deleting the account', function () {
    it('deletes an account without albums once the password is right', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        expect($user->fresh())->toBeNull();
    });

    it('refuses a wrong password and keeps the account', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->delete('/profile', ['password' => 'wrong-password'])
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        expect($user->fresh())->not->toBeNull();
    });

    it('refuses to delete an account that owns albums without confirmation', function () {
        $user = User::factory()->create();
        $album = Album::factory()->for($user, 'owner')->create();

        $this->actingAs($user)->from('/profile')
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', ['delete_albums' => 'Your albums would be deleted too. Confirm this, or cancel and keep your account.'])
            ->assertRedirect('/profile');

        expect($user->fresh())->not->toBeNull()
            ->and($album->fresh())->not->toBeNull();
    });

    it('deletes the account with its albums and the photos left in no album once confirmed', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $album = Album::factory()->for($user, 'owner')->create();
        $othersAlbum = Album::factory()->for($other, 'owner')->create();
        $othersSubAlbum = Album::factory()->for($other, 'owner')->create(['parent_id' => $album->id]);
        $onlyHere = Photo::factory()->inAlbum($album)->create(['uploader_id' => $user->id]);
        $sharedPhoto = Photo::factory()->inAlbum($album)->inAlbum($othersAlbum)->create(['uploader_id' => $user->id]);
        $unsortedOwn = Photo::factory()->inAlbum(Album::inbox($user))->create(['uploader_id' => $user->id]);
        $pageImages = Album::pageImages($user);
        $onPage = Photo::factory()->inAlbum($pageImages)->create(['uploader_id' => $user->id]);

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password', 'delete_albums' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $othersSubAlbum->refresh();

        expect($user->fresh())->toBeNull()
            ->and(Album::withTrashed()->find($album->id))->toBeNull()
            ->and(Photo::withTrashed()->find($onlyHere->id))->toBeNull()
            ->and(Photo::withTrashed()->find($unsortedOwn->id))->toBeNull()
            ->and($sharedPhoto->fresh())->not->toBeNull()
            ->and(Album::withTrashed()->where('owner_id', $user->id)->exists())->toBeFalse()
            ->and($pageImages->fresh()->owner_id)->toBeNull()
            ->and($onPage->fresh())->not->toBeNull()
            ->and($othersSubAlbum->parent_id)->toBeNull()
            ->and($othersSubAlbum->depth())->toBe(0);
    });
});

describe('downloading the own photos', function () {
    beforeEach(fn () => Storage::fake('gallery'));

    it('gives people their own photos as a zip, in a folder per album', function () {
        $owner = User::factory()->create();
        $camp = Album::factory()->for($owner, 'owner')->create(['title' => 'Camp']);
        $day = Album::factory()->for($owner, 'owner')->create(['title' => 'Day 3', 'parent_id' => $camp->id]);
        galleryPhoto($camp, $owner, 'Holiday Beach.jpg', 800, 600);
        galleryPhoto($day, $owner, 'Holiday Beach.jpg', 800, 600);
        galleryPhoto($camp, null, 'Holiday Beach.jpg', 800, 600); // someone else's upload

        $this->actingAs($owner)->get(route('profile.edit'))->assertSee('Download my photos');
        $response = $this->actingAs($owner)->get(route('profile.photos.download'));

        $response->assertOk()->assertDownload('my-photos.zip');
        expect(zipEntries($response))->toBe(['Camp/Day 3/holiday-beach.jpg', 'Camp/holiday-beach.jpg']);
    });
});

describe('active sessions', function () {
    it('lists the person\'s own sessions and marks this device', function () {
        useDatabaseSessions();
        $user = User::factory()->create();
        sessionRow($user, 'other-firefox', 'Firefox on macOS', now()->subMinutes(10)->getTimestamp());
        sessionRow($user, 'other-unknown', null, now()->subHour()->getTimestamp());
        sessionRow($user, 'expired-session', 'Opera on Linux', now()->subYear()->getTimestamp());
        sessionRow(User::factory()->create(), 'strangers-session', 'Chrome on Windows');
        $currentId = fromBrowser();

        // The first request stores this device's session, the second one lists it.
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Where you\'re signed in')
            ->assertSeeInOrder(['Unknown device', 'This device', 'Firefox on macOS', 'Last active 10 minutes ago', 'Unknown device', 'Last active 1 hour ago'])
            ->assertSee(route('profile.sessions.destroy', 'other-firefox'), false)
            ->assertDontSee(route('profile.sessions.destroy', $currentId), false)
            ->assertDontSee('Chrome on Windows')
            ->assertDontSee('Opera on Linux');
    });

    it('hides the list when sessions are not stored in the database', function (string $driver) {
        config(['session.driver' => $driver]);
        app('session')->forgetDrivers();

        $this->actingAs(User::factory()->create())->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Where you\'re signed in')
            ->assertDontSee('Sign out other devices');
    })->with(['array', 'file']);

    it('signs out another device, which is logged out on its next request', function () {
        useDatabaseSessions();
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $otherDeviceId = session()->getId();
        expect(sessionIds($user))->toBe([$otherDeviceId]);

        $thisDeviceId = fromBrowser();
        $this->actingAs($user)->delete(route('profile.sessions.destroy', $otherDeviceId))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'session-signed-out');

        expect(sessionIds($user))->toBe([$thisDeviceId]);

        nextRequest();
        fromBrowser($otherDeviceId);
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    });

    it('refuses to sign out the session of someone else', function () {
        useDatabaseSessions();
        $stranger = User::factory()->create();
        sessionRow($stranger, 'strangers-session');

        $this->actingAs(User::factory()->create())
            ->delete(route('profile.sessions.destroy', 'strangers-session'))
            ->assertNotFound();

        expect(sessionIds($stranger))->toBe(['strangers-session']);
    });

    it('refuses to sign out the current session or an unknown one with the single sign out', function () {
        useDatabaseSessions();
        $user = User::factory()->create();
        $currentId = fromBrowser();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this->delete(route('profile.sessions.destroy', $currentId))->assertNotFound();
        $this->delete(route('profile.sessions.destroy', 'no-such-session'))->assertNotFound();

        expect(sessionIds($user))->toBe([$currentId]);
    });

    it('needs the password to sign out all other devices', function (array $input) {
        useDatabaseSessions();
        $user = User::factory()->create(['remember_token' => 'known-token']);
        sessionRow($user, 'other-device');

        $this->actingAs($user)->from(route('profile.edit'))
            ->delete(route('profile.sessions.destroy-others'), $input)
            ->assertSessionHasErrorsIn('signOutOthers', 'current_password')
            ->assertRedirect(route('profile.edit'));

        expect(sessionIds($user))->toContain('other-device')
            ->and($user->fresh()->remember_token)->toBe('known-token');
    })->with(['no password' => [[]], 'a wrong password' => [['current_password' => 'wrong-password']]]);

    it('signs out all other devices, keeps this one and replaces the remember token', function () {
        useDatabaseSessions();
        mailWorks();
        $user = User::factory()->create(['remember_token' => 'known-token']);
        $stranger = User::factory()->create();
        sessionRow($user, 'other-device-1');
        sessionRow($user, 'other-device-2');
        sessionRow($stranger, 'strangers-session');
        $currentId = fromBrowser();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $before = (int) ActivityEvent::max('id');

        $this->from(route('profile.edit'))
            ->delete(route('profile.sessions.destroy-others'), ['current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'sessions-signed-out');

        expect(sessionIds($user))->toBe([$currentId])
            ->and(sessionIds($stranger))->toBe(['strangers-session'])
            ->and($user->fresh()->remember_token)->not->toBe('known-token');

        // Only the security entry, not an "updated user" one for the new token or password hash.
        $entries = ActivityEvent::where('id', '>', $before)->get();
        expect($entries)->toHaveCount(1)
            ->and($entries->first()->type)->toBe(ActivityType::SessionsSignedOut)
            ->and($entries->first()->user_id)->toBe($user->id)
            ->and($entries->first()->properties)->toMatchArray(['count' => 2]);
        Notification::assertNotSentTo($user, SessionsSignedOut::class);
        $this->get(route('profile.edit'))->assertOk()->assertSee('All other devices signed out.');
    });

    it('records signing out one device, and nothing at the lowest detail level', function (ActivityLevel $level, int $entries) {
        useDatabaseSessions();
        mailWorks();
        config(['gallery.activity_log_level' => $level->value]);
        $user = User::factory()->create();
        sessionRow($user, 'other-device');
        $before = (int) ActivityEvent::max('id');

        $this->actingAs($user)->delete(route('profile.sessions.destroy', 'other-device'))->assertRedirect(route('profile.edit'));

        expect(ActivityEvent::where('id', '>', $before)->where('type', ActivityType::SessionsSignedOut)->count())->toBe($entries);
        Notification::assertNothingSent();
    })->with([
        'changes only' => [ActivityLevel::Changes, 1],
        'off' => [ActivityLevel::Off, 0],
    ]);
});
