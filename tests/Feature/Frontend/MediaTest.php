<?php

use App\Enums\CollaboratorRole;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\Avatars;
use App\Support\SiteSettings;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

describe('a photo file', function () {
    it('is served only to visitors who can see the album, and only in sizes that exist', function () {
        $private = Album::factory()->visibility(Visibility::Private)->create();
        $public = Album::factory()->visibility(Visibility::Public)->create();
        $hidden = galleryPhoto($private, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);
        $shown = galleryPhoto($public, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);

        $this->get(route('media.show', [$hidden, 'thumb']))->assertNotFound();
        $this->get(route('media.show', [$shown, 'thumb']))->assertOk();
        $this->get(route('media.show', [$shown, '../../etc']))->assertNotFound();
        $this->actingAs($private->owner)->get(route('media.show', $hidden))->assertOk();
    });

    it('has an address for a generated conversion and none before it exists', function () {
        $uploaded = galleryPhoto(Album::factory()->create(), name: 'Holiday Beach.jpg', width: 800, height: 600);
        $withoutFile = Photo::factory()->create();

        expect($uploaded->mediaUrl('thumb'))->toBe(route('media.show', [$uploaded->original(), 'thumb']))
            ->and($withoutFile->mediaUrl('thumb'))->toBeNull();
    });

    it('is kept by the visitor\'s browser only, answers an unchanged file with "not modified" and is sent again once made anew', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $media = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);
        $url = route('media.show', [$media, 'thumb']);
        $first = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
        $etag = $first->headers->get('ETag');

        $this->get($url, ['If-None-Match' => $etag])->assertNotModified();
        $this->get($url, ['If-Modified-Since' => $first->headers->get('Last-Modified')])->assertNotModified();

        Storage::disk('gallery')->put($media->getPathRelativeToRoot('thumb'), 'a regenerated thumbnail');

        $this->get($url, ['If-None-Match' => $etag])->assertOk();

        $album->update(['visibility' => Visibility::Private]);

        $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
    });
});

describe('downloading originals', function () {
    it('lets visitors download the original from an album that allows it', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->visibility(Visibility::Public)->create(['allow_downloads' => true]);
        $media = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);
        $url = route('media.show', ['media' => $media, 'download' => 1]);

        $this->get(route('albums.show', $album))->assertOk()->assertSee("{$media->id}?download=1", false);
        $this->get($url)
            ->assertOk()
            ->assertDownload('holiday-beach.jpg');
    });

    it('still needs the album password before downloading', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->visibility(Visibility::Public)->create(['allow_downloads' => true, 'password' => 'lake-2026']);
        $media = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);
        $url = route('media.show', ['media' => $media, 'download' => 1]);

        $this->get($url)->assertNotFound();

        $this->post(route('albums.unlock', $album), ['password' => 'lake-2026']);
        $this->get($url)->assertOk();
    });

    it('always lets people who may edit the photo download it, even while downloads are off', function () {
        $photo = downloadablePhotoInPublicAlbum();
        $album = $photo->albums()->first();

        expect(SiteSettings::downloadsEnabled())->toBeFalse();
        $this->actingAs($album->owner)
            ->get(route('media.show', ['media' => $photo->original(), 'download' => 1]))
            ->assertOk();
    });

    it('does not let a visitor with a favourite download while downloads are off', function () {
        $photo = downloadablePhotoInPublicAlbum();
        $user = User::factory()->create();
        $user->favoritePhotos()->attach($photo);

        $this->actingAs($user)->get(route('media.show', ['media' => $photo->original(), 'download' => 1]))->assertNotFound();
    });

    it('serves previews but not originals through a role until downloads are on', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->visibility(Visibility::Registered)->create();
        $media = galleryPhoto($album, $album->owner)->original();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        $this->get(route('media.show', [$media, 'thumb']))->assertOk();
        $this->get(route('media.show', $media))->assertNotFound();
        $this->get(route('albums.download', $album))->assertNotFound();

        $album->update(['allow_downloads' => true]);

        $this->get(route('media.show', $media))->assertOk();
    });

    it('serves no trashed photo through a role', function () {
        $album = Album::factory()->create();
        $photo = galleryPhoto($album, $album->owner);
        $media = $photo->original();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        $photo->delete();

        $this->get(route('media.show', [$media, 'thumb']))->assertNotFound();
    });

    describe('and the position in the file', function () {
        beforeEach(fn () => Setting::put(SiteSettings::DOWNLOADS_ENABLED, true));

        it('sends the original without its position to visitors, but keeps it for people who may edit the photo or while the setting is off', function (bool $asEditor, bool $removalOn, bool $positionKept) {
            Setting::put(SiteSettings::REMOVE_PHOTO_LOCATION, $removalOn);
            $owner = User::factory()->create();
            $album = Album::factory()->create(['owner_id' => $owner->id, 'visibility' => Visibility::Public, 'allow_downloads' => true]);
            $photo = photoWithPosition($album, $owner);
            if ($asEditor) {
                $this->actingAs($owner);
            }

            $content = $this->get(route('media.show', $photo->original()))->assertOk()->streamedContent();

            expect(str_contains($content, secretLatitudeBytes()))->toBe($positionKept);
            $positionKept || expect(strlen($content))->toBe(strlen(jpegWithPosition()));
        })->with([
            'a visitor' => [false, true, false],
            'the person who may edit the photo' => [true, true, true],
            'a visitor while the setting is off' => [false, false, true],
        ]);
    });
});

describe('photo requests and the session', function () {
    it('sends photos without writing a session or setting cookies', function () {
        config(['session.driver' => 'database']);
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $url = route('media.show', [galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION), 'thumb']);

        $response = $this->get($url);

        $response->assertOk()
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
        $this->assertDatabaseCount('sessions', 0);
    });

    it('still knows who logged in on a page', function () {
        $album = Album::factory()->visibility(Visibility::Private)->create();
        $url = route('media.show', [galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION), 'thumb']);
        $this->post('/login', ['email' => $album->owner->email, 'password' => 'password']);

        $this->get($url)->assertOk();
    });

    it('still knows the albums unlocked with a share link', function () {
        $album = Album::factory()->visibility(Visibility::Unlisted)->create();
        $url = route('media.show', [galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION), 'thumb']);
        $this->get($url)->assertNotFound();

        $this->get(route('albums.shared', $album->share_token));

        $this->get($url)->assertOk();
    });

    it('does not sign anyone in from a "remember me" cookie, once per photo', function () {
        Event::fake([Login::class]);
        $album = Album::factory()->visibility(Visibility::Private)->create();
        $url = route('media.show', [galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION), 'thumb']);
        $owner = $album->owner;
        $owner->forceFill(['remember_token' => 'remembered'])->save();
        $recaller = auth()->guard()->getRecallerName();

        $response = $this->withCookie($recaller, $owner->id.'|remembered|'.$owner->password)->get($url);

        $response->assertNotFound();
        Event::assertNotDispatched(Login::class);
    });

    it('treats an outdated session as a guest for photo files', function () {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $private = Album::factory()->create(['owner_id' => $user->id, 'visibility' => Visibility::Private]);
        $public = Album::factory()->create(['owner_id' => $user->id, 'visibility' => Visibility::Public]);
        $hidden = galleryPhoto($private, $user)->original();
        $open = galleryPhoto($public, $user)->original();
        $this->post('/login', ['email' => $user->email, 'password' => 'old-password-123']);
        $this->get('/profile')->assertOk();

        $user->forceFill(['password' => Hash::make('new-password-456')])->save();
        nextRequest();
        $this->get(route('media.show', [$hidden, 'thumb']))->assertNotFound();
        nextRequest();
        $this->get(route('media.show', [$open, 'thumb']))->assertOk();
    });
});

describe('sending photos through the web server', function () {
    it('names the file to the web server instead of sending it, once access is checked', function (string $mode, string $header, string $expected) {
        config(['gallery.sendfile' => $mode]);
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $media = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);
        $file = realpath(Storage::disk('gallery')->path($media->getPathRelativeToRoot('thumb')));

        $response = $this->get(route('media.show', [$media, 'thumb']));

        $response->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
        expect($response->headers->get($header))->toBe(str_replace('{file}', substr($file, strlen(realpath(storage_path())) + 1), $expected === 'absolute' ? $file : $expected));
        expect($response->getContent())->toBe('');
    })->with([
        'Apache' => ['apache', 'X-Sendfile', 'absolute'],
        'nginx' => ['nginx', 'X-Accel-Redirect', '/og-storage/{file}'],
    ]);

    it('still refuses visitors who may not see the photo', function () {
        config(['gallery.sendfile' => 'apache']);
        $media = galleryPhoto(Album::factory()->visibility(Visibility::Private)->create(), name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);

        $this->get(route('media.show', [$media, 'thumb']))->assertNotFound()->assertHeaderMissing('X-Sendfile');
    });

    it('sends files itself unless it is turned on', function () {
        $media = galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);

        $this->get(route('media.show', [$media, 'thumb']))->assertOk()->assertHeaderMissing('X-Sendfile')->assertHeaderMissing('X-Accel-Redirect');
    });
});

describe('other files', function () {
    it('serves profile pictures to logged-in users only', function () {
        Storage::fake(Avatars::DISK);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('a.jpg')]);
        $url = Avatars::url($user->fresh());

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    });

    it('serves uploaded branding files with a script-blocking policy', function () {
        Storage::fake('local');
        Storage::disk('local')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        applySiteSettings([SiteSettings::LOGO => 'branding/logo.svg']);
        $policy = "default-src 'none'; style-src 'unsafe-inline'; img-src data:";

        $this->get(SiteSettings::assetUrl('logo'))->assertOk()->assertHeader('Content-Security-Policy', $policy);
        $this->get(route('site.asset', 'logo'))->assertOk()->assertHeader('Content-Security-Policy', $policy);
        $this->get(route('home'))->assertSee(SiteSettings::assetUrl('logo'), false);
        $this->get('/site/favicon')->assertNotFound();
    });

    it('serves theme assets without allowing path traversal', function () {
        useFixtureThemes();

        $this->get('/themes/contact-sheet/theme.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->get('/themes/contact-sheet/../theme.json')->assertNotFound();
        $this->get('/themes/contact-sheet/..%2Ftheme.json')->assertNotFound();
    });

    it('serves files on the private local disk only through signed links', function () {
        $disk = Storage::disk('local');
        $path = 'avatars/serve-test-'.uniqid().'.webp';
        $disk->put($path, 'image');

        try {
            $this->get('/storage/'.$path)->assertForbidden();
            $this->get($disk->temporaryUrl($path, now()->addMinutes(5)))->assertOk();
        } finally {
            $disk->delete($path);
        }
    });
});
