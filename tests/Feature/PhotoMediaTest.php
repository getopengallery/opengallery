<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\ConversionsFromPreview;
use App\Support\SiteSettings;
use App\Support\SmallThumbnails;
use Illuminate\Auth\Events\Login;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;

function addTestPhoto(Album $album): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    $image = imagecreatetruecolor(800, 600);
    imagejpeg($image, $path);

    return app(AddPhotoToAlbum::class)->handle($album, $path, 'Holiday Beach.jpg');
}

beforeEach(fn () => Storage::fake('gallery'));

it('stores the original, generates conversions and sets the album cover', function () {
    $album = Album::factory()->create();

    $photo = addTestPhoto($album);
    $media = $photo->getFirstMedia(Photo::COLLECTION);

    expect($photo->title)->toBe('Holiday Beach')
        ->and([$photo->width, $photo->height])->toBe([800, 600])
        ->and($media->disk)->toBe('gallery')
        ->and($media->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($album->fresh()->cover_photo_id)->toBe($photo->id);
});

it('serves media only to visitors who can see the album', function () {
    $private = Album::factory()->visibility(Visibility::Private)->create();
    $public = Album::factory()->visibility(Visibility::Public)->create();

    $hidden = addTestPhoto($private)->getFirstMedia(Photo::COLLECTION);
    $shown = addTestPhoto($public)->getFirstMedia(Photo::COLLECTION);

    $this->get(route('media.show', [$hidden, 'thumb']))->assertNotFound();
    $this->get(route('media.show', [$shown, 'thumb']))->assertOk();
    $this->actingAs($private->owner)->get(route('media.show', $hidden))->assertOk();
});

it('rejects unknown conversion names', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);

    $this->get(route('media.show', [$media, '../../etc']))->assertNotFound();
});

it('renders album photos in the justified grid at their real aspect ratio', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    addTestPhoto($album);

    $this->get(route('albums.show', $album))
        ->assertOk()
        ->assertSee('og-justified', false)
        ->assertSee('--r: 1.3333', false);
});

describe('downloading originals', function () {
    it('gives visitors only screen-size photos unless the album allows downloads', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);

        $this->get(route('media.show', [$media, 'preview']))->assertOk();
        $this->get(route('media.show', ['media' => $media, 'download' => 1]))->assertNotFound();
        $this->get(route('albums.show', $album))->assertOk()->assertDontSee('download=1');
    });

    it('lets visitors download the original from an album that allows it', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->visibility(Visibility::Public)->create(['allow_downloads' => true]);
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);
        $url = route('media.show', ['media' => $media, 'download' => 1]);

        $this->get(route('albums.show', $album))->assertOk()->assertSee("{$media->id}?download=1", false);
        $this->get($url)
            ->assertOk()
            ->assertDownload('holiday-beach.jpg');
    });

    it('still needs the album password before downloading', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->visibility(Visibility::Public)->create(['allow_downloads' => true, 'password' => 'lake-2026']);
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);
        $url = route('media.show', ['media' => $media, 'download' => 1]);

        $this->get($url)->assertNotFound();

        $this->post(route('albums.unlock', $album), ['password' => 'lake-2026']);
        $this->get($url)->assertOk();
    });

    it('always lets people who may edit the photo download it', function () {
        $album = Album::factory()->create();
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);

        $this->actingAs($album->owner)
            ->get(route('media.show', ['media' => $media, 'download' => 1]))
            ->assertOk();
    });
});

/** File names inside a streamed zip response. */
function zipEntries(TestResponse $response): array
{
    $path = tempnam(sys_get_temp_dir(), 'og-zip');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i))->sort()->values()->all();
    $zip->close();

    return $names;
}

describe('zip downloads', function () {
    it('downloads a whole album as a zip when the album allows downloads', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp', 'allow_downloads' => true]);
        addTestPhoto($album);
        addTestPhoto($album);

        $this->get(route('albums.show', $album))->assertSee(route('albums.download', $album));
        $response = $this->get(route('albums.download', $album));

        $response->assertOk()->assertDownload('summer-camp.zip');
        expect(zipEntries($response))->toBe(['holiday-beach (1).jpg', 'holiday-beach.jpg']);
    });

    it('keeps the zip from visitors while downloads are off or the password is missing', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $closed = Album::factory()->visibility(Visibility::Public)->create();
        addTestPhoto($closed);
        $locked = Album::factory()->visibility(Visibility::Public)->create(['allow_downloads' => true, 'password' => 'lake-2026']);
        addTestPhoto($locked);

        $this->get(route('albums.show', $closed))->assertDontSee(route('albums.download', $closed));
        $this->get(route('albums.download', $closed))->assertNotFound();
        $this->get(route('albums.download', $locked))->assertRedirect(route('albums.show', $locked));
    });

    it('gives people their own photos as a zip, in a folder per album', function () {
        $owner = User::factory()->create();
        $camp = Album::factory()->for($owner, 'owner')->create(['title' => 'Camp']);
        $day = Album::factory()->for($owner, 'owner')->create(['title' => 'Day 3', 'parent_id' => $camp->id]);
        $mine = addTestPhoto($camp);
        $mine->update(['uploader_id' => $owner->id]);
        $nested = addTestPhoto($day);
        $nested->update(['uploader_id' => $owner->id]);
        addTestPhoto($camp); // someone else's upload

        $this->actingAs($owner)->get(route('profile.edit'))->assertSee('Download my photos');
        $response = $this->actingAs($owner)->get(route('profile.photos.download'));

        $response->assertOk()->assertDownload('my-photos.zip');
        expect(zipEntries($response))->toBe(['Camp/Day 3/holiday-beach.jpg', 'Camp/holiday-beach.jpg']);
    });
});

it('gives the address of a generated conversion and none before it exists', function () {
    $uploaded = addTestPhoto(Album::factory()->create());
    $withoutFile = Photo::factory()->create();

    expect($uploaded->mediaUrl('thumb'))->toBe(route('media.show', [$uploaded->original(), 'thumb']))
        ->and($withoutFile->mediaUrl('thumb'))->toBeNull();
});

describe('browser caching of photo files', function () {
    it('lets only the visitor\'s browser keep a photo, and answers an unchanged one with "not modified"', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $url = route('media.show', [addTestPhoto($album)->getFirstMedia(Photo::COLLECTION), 'thumb']);
        $first = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');

        $byEtag = $this->get($url, ['If-None-Match' => $first->headers->get('ETag')]);
        $byDate = $this->get($url, ['If-Modified-Since' => $first->headers->get('Last-Modified')]);

        $byEtag->assertNotModified();
        $byDate->assertNotModified();
    });

    it('sends a photo again once its file was made anew', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);
        $url = route('media.show', [$media, 'thumb']);
        $etag = $this->get($url)->headers->get('ETag');

        Storage::disk('gallery')->put($media->getPathRelativeToRoot('thumb'), 'a regenerated thumbnail');

        $this->get($url, ['If-None-Match' => $etag])->assertOk();
    });

    it('never answers "not modified" to a visitor who may no longer see the photo', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $url = route('media.show', [addTestPhoto($album)->getFirstMedia(Photo::COLLECTION), 'thumb']);
        $etag = $this->get($url)->headers->get('ETag');

        $album->update(['visibility' => Visibility::Private]);

        $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
    });
});

it('loads the first photos of a page at once and the rest when scrolled to', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    $subAlbum = Album::factory()->visibility(Visibility::Public)->create(['parent_id' => $album->id]);
    addTestPhoto($subAlbum);
    foreach (range(1, 6) as $i) {
        addTestPhoto($album);
    }

    $response = $this->get(route('albums.show', $album));

    // One sub-album tile and six photos: the first six images load at once, the tile (first on the page) with high priority.
    $html = $response->assertOk()->getContent();
    expect(substr_count($html, 'loading="eager"'))->toBe(6);
    expect(substr_count($html, 'loading="lazy"'))->toBe(1);
    $response->assertSeeInOrder([route('albums.show', $subAlbum), 'fetchpriority="high"', 'og-justified', 'fetchpriority="auto"'], false);
});

it('makes thumbnails and previews without the image optimizers, at a fixed quality', function (string $conversion) {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);

    $manipulations = ConversionCollection::createForMedia($media)->getByName($conversion)->getManipulations();

    // The optimizers only run where binaries such as cwebp are installed, which tests cannot rely on, so this checks
    // the registered conversion: the optimizers re-encoded the finished WebP and made it up to twice as large.
    expect($manipulations->getManipulationArgument('optimize'))->toBeNull();
    expect($manipulations->getFirstManipulationArgument('quality'))->toBe(Photo::CONVERSION_QUALITY);
})->with(['preview', 'thumb', 'small']);

describe('photo requests and the session', function () {
    it('sends photos without writing a session or setting cookies', function () {
        config(['session.driver' => 'database']);
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $url = route('media.show', [addTestPhoto($album)->getFirstMedia(Photo::COLLECTION), 'thumb']);

        $response = $this->get($url);

        $response->assertOk()
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
        $this->assertDatabaseCount('sessions', 0);
    });

    it('still knows who logged in on a page', function () {
        $album = Album::factory()->visibility(Visibility::Private)->create();
        $url = route('media.show', [addTestPhoto($album)->getFirstMedia(Photo::COLLECTION), 'thumb']);
        $this->post('/login', ['email' => $album->owner->email, 'password' => 'password']);

        $this->get($url)->assertOk();
    });

    it('still knows the albums unlocked with a share link', function () {
        $album = Album::factory()->visibility(Visibility::Unlisted)->create();
        $url = route('media.show', [addTestPhoto($album)->getFirstMedia(Photo::COLLECTION), 'thumb']);
        $this->get($url)->assertNotFound();

        $this->get(route('albums.shared', $album->share_token));

        $this->get($url)->assertOk();
    });

    it('does not sign anyone in from a "remember me" cookie, once per photo', function () {
        Event::fake([Login::class]);
        $album = Album::factory()->visibility(Visibility::Private)->create();
        $url = route('media.show', [addTestPhoto($album)->getFirstMedia(Photo::COLLECTION), 'thumb']);
        $owner = $album->owner;
        $owner->forceFill(['remember_token' => 'remembered'])->save();
        $recaller = auth()->guard()->getRecallerName();

        $response = $this->withCookie($recaller, $owner->id.'|remembered|'.$owner->password)->get($url);

        $response->assertNotFound();
        Event::assertNotDispatched(Login::class);
    });
});

describe('photo sizes', function () {
    it('makes the three sizes of a new photo, each fitting its longest side', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);

        $sizes = collect(['preview', 'thumb', 'small'])->mapWithKeys(fn (string $conversion) => [
            $conversion => getimagesizefromstring(Storage::disk('gallery')->get($media->getPathRelativeToRoot($conversion)))[0],
        ]);

        // The 800 x 600 test photo is never enlarged: the preview keeps 800.
        expect($sizes->all())->toBe(['preview' => 800, 'thumb' => 720, 'small' => 360]);
        // The preview comes first, so the smaller sizes can start from it (ConversionsFromPreview).
        expect(ConversionCollection::createForMedia($media)->map->getName()->all())->toBe(['preview', 'thumb', 'small']);
    });

    it('starts the smaller sizes from the preview made in the same run, not from the original', function (string $conversion, bool $previewInRun, string $expected) {
        $folder = sys_get_temp_dir().'/og-run-'.Str::random(8);
        mkdir($folder);
        touch($original = $folder.'/original.jpg');
        if ($previewInRun) {
            touch($folder.'/Holiday-Beach-preview.webp');
        }

        $source = (new ConversionsFromPreview)->convert($original, Conversion::create($conversion));

        expect(basename($source))->toBe($expected);
        (new Filesystem)->deleteDirectory($folder);
    })->with([
        'thumb after the preview' => ['thumb', true, 'Holiday-Beach-preview.webp'],
        'small after the preview' => ['small', true, 'Holiday-Beach-preview.webp'],
        'the preview itself' => ['preview', true, 'original.jpg'],
        'thumb made on its own' => ['thumb', false, 'original.jpg'],
    ]);

    it('lets the browser choose between the small and the large thumbnail by their real widths', function (int $width, int $height, string $expected) {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
        imagejpeg(imagecreatetruecolor($width, $height), $path);
        $media = app(AddPhotoToAlbum::class)->handle($album, $path, 'Photo.jpg')->getFirstMedia(Photo::COLLECTION);

        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee('srcset="'.route('media.show', [$media, 'small']).' '.explode(',', $expected)[0].'w, '.route('media.show', [$media, 'thumb']).' '.explode(',', $expected)[1].'w"', false);
        $this->get(route('media.show', [$media, 'small']))->assertOk();
    })->with([
        'landscape' => [800, 600, '360,720'],
        'portrait' => [600, 800, '270,540'],
    ]);

    it('offers only the large thumbnail while the small one is not made yet', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);
        $media->markAsConversionNotGenerated('small');

        $response = $this->get(route('albums.show', $album));

        $response->assertOk()->assertSee(route('media.show', [$media, 'thumb']), false)->assertDontSee('srcset=', false);
    });

    it('makes the small size of older photos from their thumbnail in the background', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);
        Storage::disk('gallery')->delete($media->getPathRelativeToRoot('small'));
        $media->markAsConversionNotGenerated('small');

        $made = SmallThumbnails::makeMissing();

        expect($made)->toBe(1);
        expect($media->fresh()->hasGeneratedConversion('small'))->toBeTrue();
        expect(getimagesizefromstring(Storage::disk('gallery')->get($media->getPathRelativeToRoot('small')))[0])->toBe(360);
    });
});

describe('sending photos through the web server', function () {
    it('names the file to the web server instead of sending it, once access is checked', function (string $mode, string $header, string $expected) {
        config(['gallery.sendfile' => $mode]);
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $media = addTestPhoto($album)->getFirstMedia(Photo::COLLECTION);
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
        $media = addTestPhoto(Album::factory()->visibility(Visibility::Private)->create())->getFirstMedia(Photo::COLLECTION);

        $this->get(route('media.show', [$media, 'thumb']))->assertNotFound()->assertHeaderMissing('X-Sendfile');
    });

    it('sends files itself unless it is turned on', function () {
        $media = addTestPhoto(Album::factory()->visibility(Visibility::Public)->create())->getFirstMedia(Photo::COLLECTION);

        $this->get(route('media.show', [$media, 'thumb']))->assertOk()->assertHeaderMissing('X-Sendfile')->assertHeaderMissing('X-Accel-Redirect');
    });
});

/** A JPEG of $width x $height pixels stored with EXIF orientation $orientation, as phones save photos taken upright. */
function turnedJpeg(int $width, int $height, int $orientation): string
{
    ob_start();
    imagejpeg(imagecreatetruecolor($width, $height));
    $jpeg = (string) ob_get_clean();
    $exif = "Exif\x00\x00II*\x00".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    file_put_contents($path, "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));

    return $path;
}

describe('photos taken upright', function () {
    it('stores the size of photos taken upright as they are seen, and keeps their files upright', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();

        $photo = app(AddPhotoToAlbum::class)->handle($album, turnedJpeg(800, 600, 6), 'Upright.jpg');

        $media = $photo->getFirstMedia(Photo::COLLECTION);
        $size = fn (string $conversion) => array_slice(getimagesizefromstring(Storage::disk('gallery')->get($media->getPathRelativeToRoot($conversion))), 0, 2);
        expect([$photo->width, $photo->height])->toBe([600, 800]);
        expect([$size('preview'), $size('thumb')])->toBe([[600, 800], [540, 720]]);
    });
});
