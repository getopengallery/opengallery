<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\SignUpAttempted;
use App\Support\AlbumCollaboration;
use App\Support\Invitations;
use App\Support\PhotoLocation;
use App\Support\SiteSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const SECRET_LATITUDE = [52, 1, 31, 1, 1234, 100];

/** A TIFF block as found in EXIF: a camera make and a GPS position. */
function exifWithPosition(bool $littleEndian = true): string
{
    $short = fn (int $value) => pack($littleEndian ? 'v' : 'n', $value);
    $long = fn (int $value) => pack($littleEndian ? 'V' : 'N', $value);
    $entry = fn (int $tag, int $type, int $count, string $value) => $short($tag).$short($type).$long($count).$value;
    $rationals = fn (array $values) => implode('', array_map($long, $values));

    return ($littleEndian ? "II\x2A\0" : "MM\0\x2A").$long(8)
        // First directory: make, pointer to the GPS block.
        .$short(2)
        .$entry(0x010F, 2, 5, $long(38))
        .$entry(0x8825, 4, 1, $long(44))
        .$long(0)
        ."Acme\0\0"
        // GPS block: latitude and longitude with their references.
        .$short(4)
        .$entry(1, 2, 2, "N\0\0\0")
        .$entry(2, 5, 3, $long(98))
        .$entry(3, 2, 2, "E\0\0\0")
        .$entry(4, 5, 3, $long(122))
        .$long(0)
        .$rationals(SECRET_LATITUDE)
        .$rationals([13, 1, 24, 1, 5678, 100]);
}

function secretLatitudeBytes(bool $littleEndian = true): string
{
    return implode('', array_map(fn (int $value) => pack($littleEndian ? 'V' : 'N', $value), SECRET_LATITUDE));
}

function testImage(string $format): string
{
    $image = imagecreatetruecolor(32, 24);
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image),
        'png' => imagepng($image),
        'webp' => imagewebp($image),
    };

    return (string) ob_get_clean();
}

function jpegWithPosition(bool $littleEndian = true, string $extraSegment = ''): string
{
    $payload = "Exif\0\0".exifWithPosition($littleEndian);

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload.$extraSegment.substr(testImage('jpeg'), 2);
}

function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

function pngWithChunk(string $chunk): string
{
    $png = testImage('png');

    return substr($png, 0, 33).$chunk.substr($png, 33);
}

function webpWithPosition(): string
{
    $webp = testImage('webp').'';
    $exif = exifWithPosition();
    $body = substr($webp, 8).'EXIF'.pack('V', strlen($exif)).$exif;

    return 'RIFF'.pack('V', strlen($body)).$body;
}

function withoutLocation(string $bytes): string
{
    $source = fopen('php://temp', 'w+b');
    fwrite($source, $bytes);
    rewind($source);
    $result = PhotoLocation::withoutLocation($source);
    $clean = (string) stream_get_contents($result);
    fclose($result);

    return $clean;
}

function pngChecksumsAreValid(string $png): bool
{
    for ($position = 8; $position + 12 <= strlen($png);) {
        $length = unpack('N', substr($png, $position, 4))[1];
        $stored = unpack('N', substr($png, $position + 8 + $length, 4))[1];

        if (crc32(substr($png, $position + 4, 4 + $length)) !== $stored) {
            return false;
        }

        $position += 12 + $length;
    }

    return true;
}

function photoWithPosition(Album $album, ?User $uploader = null): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    file_put_contents($path, jpegWithPosition());

    return app(AddPhotoToAlbum::class)->handle($album, $path, 'Lake.jpg', $uploader);
}

/** Forgets who is logged in between requests, as a new request from that browser would. */
function nextRequest(): void
{
    Auth::forgetGuards();
    app()->forgetScopedInstances();
}

describe('links in e-mails', function () {
    it('builds the reset link from the site address when the Host header is forged', function () {
        config(['app.url' => 'https://gallery.example']);
        $user = User::factory()->create();

        $this->post('http://evil.test/forgot-password', ['email' => $user->email]);

        $mail = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHtmlBody();
        expect($mail)->toContain('https://gallery.example/reset-password/')->not->toContain('evil.test');
    });

    it('keeps the address visitors came through when it belongs to the site', function (string $host) {
        config(['app.url' => 'https://gallery.example', 'gallery.trusted_hosts' => 'photos.example']);

        $this->get("https://{$host}/login")->assertSee("https://{$host}/login", false);
    })->with(['the configured address' => 'gallery.example', 'its www twin' => 'www.gallery.example', 'a trusted host' => 'photos.example']);
});

describe('reusing photos', function () {
    beforeEach(fn () => Storage::fake('gallery'));

    it('refuses a cover the person may only look at', function () {
        $owner = User::factory()->create();
        $viewer = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $restricted = Album::factory()->create(['owner_id' => $owner->id, 'visibility' => Visibility::Restricted]);
        $restricted->sharedUsers()->attach($viewer);
        $photo = collaborationPhoto($restricted, $owner);
        $own = Album::factory()->create(['owner_id' => $viewer->id, 'visibility' => Visibility::Public, 'allow_downloads' => true]);

        $this->actingAs($viewer);

        Livewire::test(EditAlbum::class, ['record' => $own->getRouteKey()])
            ->fillForm(['cover_photo_id' => $photo->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($own->fresh()->cover_photo_id)->not->toBe($photo->id)
            ->and($own->photos()->whereKey($photo->id)->exists())->toBeFalse()
            ->and($viewer->fresh()->can('update', $photo->fresh()))->toBeFalse();
    });

    it('matches who may edit a photo', function (Closure $person, bool $reusable) {
        $owner = User::factory()->create();
        $album = Album::factory()->create(['owner_id' => $owner->id, 'visibility' => Visibility::Public]);
        $photo = collaborationPhoto($album, User::factory()->create());
        $user = $person($album, $photo, $owner);

        expect(Photo::reusableBy($user)->whereKey($photo->id)->exists())->toBe($reusable)
            ->and($user->can('update', $photo))->toBe($reusable);
    })->with([
        'a visitor of the public album' => [fn () => User::factory()->create(), false],
        'its uploader' => [fn ($album, $photo) => $photo->uploader, true],
        'the album owner' => [fn ($album, $photo, $owner) => $owner, true],
        'staff who may edit any album' => [fn () => tap(User::factory()->create())->givePermissionTo(Permission::EditAnyAlbum->value), true],
    ]);

    it('leaves photos the person cannot edit out when adding to their own album', function () {
        $viewer = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $public = Album::factory()->create(['owner_id' => User::factory()->create()->id, 'visibility' => Visibility::Public]);
        $foreign = collaborationPhoto($public, $public->owner);
        $own = Album::factory()->create(['owner_id' => $viewer->id]);
        $mine = collaborationPhoto($own, $viewer);

        expect(AlbumCollaboration::addablePhotoIds($own, $viewer, [$foreign->id, $mine->id]))->toBe([$mine->id]);
    });
});

describe('sessions and e-mail changes', function () {
    it('signs out other sessions when the password changes', function () {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'old-password-123']);
        $this->get('/profile')->assertOk();

        $user->forceFill(['password' => Hash::make('new-password-456')])->save();
        nextRequest();

        $this->get('/profile')->assertRedirect(route('login'));
    });

    it('keeps this device signed in and replaces the remember token after a password change', function () {
        $user = User::factory()->create(['remember_token' => 'stolen-remember-token']);

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertSessionHasNoErrors();

        expect($user->fresh()->remember_token)->not->toBe('stolen-remember-token');
        $this->get('/profile')->assertOk();
    });

    it('treats an outdated session as a guest for photo files', function () {
        Storage::fake('gallery');
        $user = User::factory()->create(['password' => 'old-password-123']);
        $private = Album::factory()->create(['owner_id' => $user->id, 'visibility' => Visibility::Private]);
        $public = Album::factory()->create(['owner_id' => $user->id, 'visibility' => Visibility::Public]);
        $hidden = collaborationPhoto($private, $user)->original();
        $open = collaborationPhoto($public, $user)->original();
        $this->post('/login', ['email' => $user->email, 'password' => 'old-password-123']);
        $this->get('/profile')->assertOk();

        $user->forceFill(['password' => Hash::make('new-password-456')])->save();
        nextRequest();
        $this->get(route('media.show', [$hidden, 'thumb']))->assertNotFound();
        nextRequest();
        $this->get(route('media.show', [$open, 'thumb']))->assertOk();
    });

    it('needs the current password to change the e-mail address', function (array $input) {
        $user = User::factory()->create(['email' => 'grace@example.com']);

        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => 'new@example.com', ...$input])
            ->assertSessionHasErrors('current_password');

        expect($user->fresh()->email)->toBe('grace@example.com');
    })->with(['no password' => [[]], 'a wrong password' => [['current_password' => 'wrong-password']]]);
});

describe('existing accounts stay hidden', function () {
    it('answers the same on the forgotten password form', function () {
        Notification::fake();
        $user = User::factory()->create();
        $expected = 'If an account exists for this address, we have sent a link to choose a new password.';

        foreach ([$user->email, $user->email, 'nobody@example.com'] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')
                ->assertSessionHas('status', $expected)
                ->assertSessionHasNoErrors();
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    });

    it('answers the same on the reset form for an unknown address and a wrong link', function (string $email) {
        User::factory()->create(['email' => 'known@example.com']);

        $this->from('/reset-password/x')->post('/reset-password', [
            'token' => 'wrong-token',
            'email' => $email,
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertSessionHasErrors(['email' => 'This password reset token is invalid.']);
    })->with(['known@example.com', 'nobody@example.com']);

    it('answers the same on the invitation form for an unknown address and a wrong link', function (string $email) {
        Invitations::link(User::factory()->create(['email' => 'known@example.com']));

        $this->from('/invitation/x')->post('/invitation', [
            'token' => 'wrong-token',
            'email' => $email,
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertSessionHasErrors(['email' => 'This invitation link is no longer valid. Ask for a new one.']);
    })->with(['known@example.com', 'nobody@example.com']);

    it('answers the same when signing up with a taken address and tells its owner once', function () {
        config(['mail.default' => 'smtp', 'gallery.registration_enabled' => true]);
        Notification::fake();
        $owner = User::factory()->create(['email' => 'taken@example.com', 'name' => 'Owner']);
        $signUp = fn (string $email) => $this->post('/register', [
            'name' => 'Someone',
            'email' => $email,
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ]);

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

    it('reports a taken address while no confirmation e-mail can be sent', function () {
        config(['gallery.registration_enabled' => true]);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertSessionHasErrors('email');
    });
});

describe('rate limits', function () {
    it('limits forms that guests can repeat', function (string $url, Closure $input, int $allowed) {
        config(['gallery.registration_enabled' => true]);
        Notification::fake();

        foreach (range(1, $allowed) as $attempt) {
            expect($this->post($url, $input($attempt))->status())->not->toBe(429);
            Auth::logout();
        }

        $this->post($url, $input($allowed + 1))->assertTooManyRequests();
    })->with([
        'login across accounts' => ['/login', fn (int $i) => ['email' => "u{$i}@example.com", 'password' => 'Summer2026!'], 30],
        'forgotten password' => ['/forgot-password', fn (int $i) => ['email' => "u{$i}@example.com"], 5],
        'reset form' => ['/reset-password', fn (int $i) => ['token' => "t{$i}", 'email' => 'u@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'], 10],
        'registration' => ['/register', fn (int $i) => ['name' => 'Bot', 'email' => "bot{$i}@example.com", 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'], 5],
    ]);

    it('counts password guesses per account across forms', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (range(1, 3) as $attempt) {
            $this->post('/confirm-password', ['password' => "wrong-{$attempt}"]);
            $this->put('/password', ['current_password' => "wrong-{$attempt}", 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);
        }

        $this->delete('/profile', ['password' => 'wrong'])->assertTooManyRequests();
        expect(User::find($user->id))->not->toBeNull();
    });

    it('does not limit saving a profile without a password', function () {
        $user = User::factory()->create();

        foreach (range(1, 8) as $attempt) {
            $this->actingAs($user)->patch('/profile', ['name' => "Name {$attempt}", 'email' => $user->email])
                ->assertRedirect('/profile');
        }
    });
});

describe('security headers', function () {
    it('protects pages in the browser', function () {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
    });

    it('asks browsers to stay on HTTPS only over HTTPS', function () {
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=15552000');
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    });

    it('can turn HTTPS pinning off', function () {
        config(['gallery.hsts_max_age' => 0]);

        $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    });

    it('keeps the stricter policy of uploaded files', function () {
        Storage::fake('local');
        Storage::disk('local')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        Setting::put(SiteSettings::LOGO, 'branding/logo.svg');

        $this->get(route('site.asset', 'logo'))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:");
    });
});

describe('share links', function () {
    it('ends access from an earlier link when the album gets a new one', function () {
        $album = Album::factory()->create(['visibility' => Visibility::Unlisted]);
        $this->get(route('albums.shared', $album->share_token))->assertOk();

        $album->update(['visibility' => Visibility::Private]);
        $album->update(['visibility' => Visibility::Unlisted]);

        $this->get(route('albums.show', $album->fresh()))->assertNotFound();
        $this->get(route('albums.shared', $album->fresh()->share_token))->assertOk();
        $this->get(route('albums.show', $album->fresh()))->assertOk();
    });
});

describe('location in photo files', function () {
    it('removes the GPS position from a JPEG and keeps everything else', function (bool $littleEndian) {
        $original = jpegWithPosition($littleEndian);
        $clean = withoutLocation($original);
        $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
        file_put_contents($path, $clean);
        $exif = @exif_read_data($path);

        expect(strlen($clean))->toBe(strlen($original))
            ->and(str_contains($original, secretLatitudeBytes($littleEndian)))->toBeTrue()
            ->and(str_contains($clean, secretLatitudeBytes($littleEndian)))->toBeFalse()
            ->and($exif['Make'])->toBe('Acme')
            ->and($exif)->not->toHaveKey('GPSLatitude')
            ->and(imagecreatefromstring($clean))->not->toBeFalse();
    })->with(['little endian' => true, 'big endian' => false]);

    it('removes it from a PNG and keeps the file valid', function () {
        $clean = withoutLocation(pngWithChunk(pngChunk('eXIf', exifWithPosition())));

        expect(str_contains($clean, secretLatitudeBytes()))->toBeFalse()
            ->and(str_contains($clean, 'Acme'))->toBeTrue()
            ->and(pngChecksumsAreValid($clean))->toBeTrue()
            ->and(imagecreatefromstring($clean))->not->toBeFalse();
    });

    it('blanks compressed XMP in a PNG', function () {
        $xmp = "XML:com.adobe.xmp\0\1\0\0\0".gzcompress('<x:xmpmeta exif:GPSLatitude="52,31.0N"/>');

        $clean = withoutLocation(pngWithChunk(pngChunk('iTXt', $xmp)));

        expect(str_contains($clean, gzcompress('<x:xmpmeta exif:GPSLatitude="52,31.0N"/>')))->toBeFalse()
            ->and(pngChecksumsAreValid($clean))->toBeTrue();
    });

    it('removes it from a WebP', function () {
        $clean = withoutLocation(webpWithPosition());

        expect(str_contains($clean, secretLatitudeBytes()))->toBeFalse()
            ->and(str_contains($clean, 'Acme'))->toBeTrue()
            ->and(imagecreatefromstring($clean))->not->toBeFalse();
    });

    it('blanks GPS values in XMP, as attributes and as elements', function () {
        $xmp = '<x:xmpmeta exif:GPSLatitude="52,31.0N" tiff:Make="Acme"><exif:GPSLongitude>13,24.0E</exif:GPSLongitude></x:xmpmeta>';
        $segment = "\xFF\xE1".pack('n', strlen($xmp) + 31)."http://ns.adobe.com/xap/1.0/\0".$xmp;

        $clean = withoutLocation(jpegWithPosition(extraSegment: $segment));

        expect($clean)->not->toContain('52,31.0N')
            ->not->toContain('13,24.0E')
            ->toContain('tiff:Make="Acme"');
    });

    it('leaves a file without a position untouched', function () {
        $image = testImage('jpeg');

        expect(withoutLocation($image))->toBe($image);
    });

    it('sends visitors the original without its position', function () {
        Storage::fake('gallery');
        $album = Album::factory()->create(['visibility' => Visibility::Public, 'allow_downloads' => true]);
        $photo = photoWithPosition($album);

        $content = $this->get(route('media.show', $photo->original()))->assertOk()->streamedContent();

        expect(str_contains($content, secretLatitudeBytes()))->toBeFalse()
            ->and(strlen($content))->toBe(strlen(jpegWithPosition()));
    });

    it('keeps the position for people who may edit the photo', function () {
        Storage::fake('gallery');
        $owner = User::factory()->create();
        $album = Album::factory()->create(['owner_id' => $owner->id, 'visibility' => Visibility::Public, 'allow_downloads' => true]);
        $photo = photoWithPosition($album, $owner);

        $content = $this->actingAs($owner)->get(route('media.show', $photo->original()))->assertOk()->streamedContent();

        expect(str_contains($content, secretLatitudeBytes()))->toBeTrue();
    });

    it('keeps the position for everyone while the setting is off', function () {
        Storage::fake('gallery');
        Setting::put(SiteSettings::REMOVE_PHOTO_LOCATION, false);
        $album = Album::factory()->create(['visibility' => Visibility::Public, 'allow_downloads' => true]);
        $photo = photoWithPosition($album);

        $content = $this->get(route('media.show', $photo->original()))->assertOk()->streamedContent();

        expect(str_contains($content, secretLatitudeBytes()))->toBeTrue();
    });

    it('takes the position out of album zips for visitors', function () {
        Storage::fake('gallery');
        $album = Album::factory()->create(['visibility' => Visibility::Public, 'allow_downloads' => true]);
        photoWithPosition($album);

        $path = tempnam(sys_get_temp_dir(), 'og').'.zip';
        file_put_contents($path, $this->get(route('albums.download', $album))->assertOk()->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $file = (string) $zip->getFromIndex(0);
        $zip->close();

        expect(strlen($file))->toBe(strlen(jpegWithPosition()))
            ->and(str_contains($file, secretLatitudeBytes()))->toBeFalse();
    });

    it('never changes the stored file', function () {
        Storage::fake('gallery');
        $album = Album::factory()->create(['visibility' => Visibility::Public, 'allow_downloads' => true]);
        $media = photoWithPosition($album)->original();

        $this->get(route('media.show', $media))->assertOk()->streamedContent();

        expect(str_contains(Storage::disk('gallery')->get($media->getPathRelativeToRoot()), secretLatitudeBytes()))->toBeTrue();
    });
});
