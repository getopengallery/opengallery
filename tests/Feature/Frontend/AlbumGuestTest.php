<?php

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\AlbumGuest;
use App\Models\Setting;
use App\Support\AlbumGuests;
use App\Support\AlbumSettings;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

describe('the guest link', function () {
    it('e-mails a code and asks for it, without sending another while the first can still be entered', function () {
        [$album, $link] = guestAlbum();

        $this->get($link)->assertOk()->assertSee('Camp')->assertSee('g••••@example.com')->assertSee('Open album');
        $code = guestCode();

        expect($code)->toMatch('/^\d{6}$/')
            ->and(mailsSent())->toBe(1);

        $this->get($link)->assertOk();
        $this->travel(AlbumGuests::CODE_MINUTES - 1)->minutes();
        $this->get($link)->assertOk();

        expect(mailsSent())->toBe(1);

        $this->travel(2)->minutes();
        $this->get($link)->assertOk();

        expect(mailsSent())->toBe(2)
            ->and(guestCode())->not->toBeNull();
    });

    it('sends a new code on request', function () {
        [$album, $link] = guestAlbum();
        $this->get($link);

        $this->post($link.'/resend')->assertRedirect()->assertSessionHas('status');

        expect(mailsSent())->toBe(2);
    });

    it('does not open an unknown link, nor the link of another album', function () {
        [$album, $link] = guestAlbum();
        $other = Album::factory()->visibility(Visibility::Restricted)->create();
        $token = basename($link);

        $this->get(route('albums.guest', [$album, 'not-a-token']))->assertNotFound();
        $this->get(route('albums.guest', [$other, $token]))->assertNotFound();
        $this->post(route('albums.guest', [$other, $token]), ['code' => '123456'])->assertNotFound();

        expect(mailsSent())->toBe(0);
    });

    it('keeps the album closed until the code was entered', function () {
        [$album, $link] = guestAlbum();
        $photo = galleryPhoto($album);

        $this->get($link);

        $this->get(route('albums.show', $album))->assertNotFound();
        $this->get(route('albums.photos', $album))->assertNotFound();
        $this->get(route('media.show', [$photo->original(), 'thumb']))->assertNotFound();
    });
});

describe('the code', function () {
    it('opens the album, its photos and their files for the browser session', function () {
        [$album, $link] = guestAlbum();
        $photo = galleryPhoto($album);
        $this->get($link);

        enterGuestCode($link)->assertRedirect(route('albums.show', $album));

        $this->get(route('albums.show', $album))->assertOk()->assertSee('Camp');
        $this->get(route('albums.photos', $album))->assertOk()->assertJsonCount(1, 'items');
        $this->get(route('media.show', [$photo->original(), 'thumb']))->assertOk();
        $this->get(route('media.show', [$photo->original(), 'preview']))->assertOk();
        $this->get($link)->assertRedirect(route('albums.show', $album));

        expect(AlbumGuest::sole()->last_used_at)->not->toBeNull();
    });

    it('can be entered with spaces in it', function () {
        [$album, $link] = guestAlbum();
        $this->get($link);
        $code = guestCode();

        enterGuestCode($link, substr($code, 0, 3).' '.substr($code, 3))->assertRedirect(route('albums.show', $album));
    });

    it('fails when it is wrong, and logs it', function () {
        [$album, $link] = guestAlbum();
        $this->get($link);
        $wrong = guestCode() === '000000' ? '000001' : '000000';

        enterGuestCode($link, $wrong)->assertSessionHasErrors('code');

        $this->get(route('albums.show', $album))->assertNotFound();
        expect(AlbumGuest::sole()->code_attempts)->toBe(1)
            ->and(ActivityEvent::where('type', ActivityType::AlbumGuestCodeFailed)->sole()->summary())
            ->toBe('A guest entered a wrong guest code for Camp');
    });

    it('stops working after five wrong tries, even with the right code', function () {
        [$album, $link] = guestAlbum();
        $this->get($link);
        $right = guestCode();
        $wrong = $right === '000000' ? '000001' : '000000';

        foreach (range(1, AlbumGuests::MAX_ATTEMPTS) as $try) {
            enterGuestCode($link, $wrong)->assertSessionHasErrors('code');
        }
        enterGuestCode($link, $right)->assertSessionHasErrors('code');

        $this->get(route('albums.show', $album))->assertNotFound();
        expect(ActivityEvent::where('type', ActivityType::AlbumGuestCodeFailed)->count())->toBe(AlbumGuests::MAX_ATTEMPTS);

        $this->get($link)->assertOk();
        expect(mailsSent())->toBe(2);

        enterGuestCode($link)->assertRedirect(route('albums.show', $album));
    });

    it('stops working after ten minutes', function () {
        [$album, $link] = guestAlbum();
        $this->get($link);
        $code = guestCode();

        $this->travel(AlbumGuests::CODE_MINUTES + 1)->minutes();

        enterGuestCode($link, $code)->assertSessionHasErrors('code');
        $this->get(route('albums.show', $album))->assertNotFound();
    });

    it('works only once', function () {
        [$album, $link] = guestAlbum();
        $this->get($link);
        $code = guestCode();
        enterGuestCode($link, $code)->assertRedirect(route('albums.show', $album));

        $this->flushSession();

        enterGuestCode($link, $code)->assertSessionHasErrors('code');
        $this->get(route('albums.show', $album))->assertNotFound();
    });

    it('must be filled in', function () {
        [$album, $link] = guestAlbum();
        $this->get($link);

        $this->post($link, ['code' => ''])->assertSessionHasErrors('code');
    });
});

describe('when access ends', function () {
    beforeEach(function () {
        [$this->album, $this->link] = guestAlbum();
        $this->get($this->link);
        enterGuestCode($this->link)->assertRedirect();
        $this->get(route('albums.show', $this->album))->assertOk();
    });

    it('ends when the guest is removed', function () {
        AlbumSettings::syncShareKeys($this->album, [], userWithRole(Role::Admin));

        $this->get(route('albums.show', $this->album))->assertNotFound();
        $this->get($this->link)->assertNotFound();
    });

    it('ends when the album is no longer shared with specific people', function (Visibility $visibility) {
        $this->album->update(['visibility' => $visibility]);

        $this->get(route('albums.show', $this->album))->assertNotFound();
        $this->get(route('albums.photos', $this->album))->assertNotFound();
        $this->get($this->link)->assertStatus(410);
    })->with([Visibility::Private, Visibility::Unlisted, Visibility::Registered]);

    it('ends on the album end date', function () {
        $this->album->update(['access_expires_at' => now()->addDay()]);
        $this->get(route('albums.show', $this->album))->assertOk();

        $this->travel(2)->days();

        $this->get(route('albums.show', $this->album))->assertNotFound();
        $this->get($this->link)->assertStatus(410)->assertSee('This link has expired');
    });

    it('ends while sharing by e-mail is turned off, and comes back with it', function () {
        Setting::put(SiteSettings::ALBUM_GUESTS, false);

        $this->get(route('albums.show', $this->album))->assertNotFound();
        $this->get($this->link)->assertStatus(410);

        Setting::put(SiteSettings::ALBUM_GUESTS, true);

        $this->get(route('albums.show', $this->album))->assertOk();
    });

    it('still asks for the album password after the code', function () {
        $this->album->update(['password' => 'lake-2026']);

        $this->get(route('albums.show', $this->album))->assertStatus(401);

        $this->post(route('albums.unlock', $this->album), ['password' => 'lake-2026']);

        $this->get(route('albums.show', $this->album))->assertOk();
    });

    it('is only for the guest of that album', function () {
        $other = Album::factory()->visibility(Visibility::Restricted)->create();

        $this->get(route('albums.show', $other))->assertNotFound();
    });
});

describe('days guests keep access', function () {
    it('ends after 30 days by default and shows the guest an explanation', function () {
        [$album, $link] = guestAlbum();
        $this->get($link);
        enterGuestCode($link);

        $this->travel(29)->days();
        $this->get(route('albums.show', $album))->assertOk();

        $this->travel(2)->days();
        $this->get(route('albums.show', $album))->assertNotFound();
        $this->get($link)->assertStatus(410);
        $this->post($link, ['code' => '123456'])->assertNotFound();
    });

    it('follows the setting, and never ends with 0', function (int $days, bool $stillOpen) {
        Setting::put(SiteSettings::ALBUM_GUEST_DAYS, $days);
        [$album, $link] = guestAlbum();
        $this->get($link);
        enterGuestCode($link);

        $this->travel(400)->days();

        $this->get(route('albums.show', $album))->assertStatus($stillOpen ? 200 : 404);
        $this->get($link)->assertStatus($stillOpen ? 302 : 410);
    })->with([
        'no limit' => [0, true],
        'a year' => [365, false],
        'two years' => [730, true],
    ]);
});
