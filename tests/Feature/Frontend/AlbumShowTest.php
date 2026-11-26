<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\CollaboratorRole;
use App\Enums\PhotoOrder;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\AlbumAccess;
use App\Support\AlbumDeletion;
use App\Support\AlbumPreviewImage;
use App\Support\PageMeta;
use App\Support\PhotoExif;
use App\Support\PhotoGridItems;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;

beforeEach(fn () => Storage::fake('gallery'));

describe('who may open an album', function () {
    it('opens for the people its visibility allows and is closed to everyone else', function (Visibility $visibility, string $viewer, int $status) {
        $album = Album::factory()->visibility($visibility)->create();
        $user = frontendAlbumViewer($viewer, $album);
        if ($user) {
            $this->actingAs($user);
        }

        $response = $this->get(route('albums.show', $album))->assertStatus($status);

        $status === 200
            ? $response->assertSee($album->title)
            : $response->assertDontSee($album->title);
    })->with([
        'public to a guest' => [Visibility::Public, 'guest', 200],
        'unlisted to a guest without its link' => [Visibility::Unlisted, 'guest', 404],
        'registered-only to a guest' => [Visibility::Registered, 'guest', 404],
        'restricted to a guest' => [Visibility::Restricted, 'guest', 404],
        'private to a guest' => [Visibility::Private, 'guest', 404],
        'registered-only to any member' => [Visibility::Registered, 'member', 200],
        'restricted to a person it is shared with' => [Visibility::Restricted, 'person it is shared with', 200],
        'restricted to a member of a group it is shared with' => [Visibility::Restricted, 'member of a group it is shared with', 200],
        'restricted to another member' => [Visibility::Restricted, 'member', 403],
        'private to its owner' => [Visibility::Private, 'owner', 200],
        'private to staff' => [Visibility::Private, 'moderator', 200],
        'private to another member' => [Visibility::Private, 'member', 403],
    ]);

    it('lists a restricted album only for the people it is shared with', function () {
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $viaGroup = frontendAlbumViewer('member of a group it is shared with', $album);
        $outsider = frontendAlbumViewer('member', $album);

        expect(Album::listableFor($viaGroup)->pluck('id'))->toContain($album->id)
            ->and(Album::listableFor($outsider)->pluck('id'))->not->toContain($album->id);
    });

    it('tells members who may not see an album what to do, without showing the album', function () {
        $album = Album::factory()->create(['title' => 'Secret plans']);

        $this->actingAs(userWithRole(Role::User))
            ->get(route('albums.show', $album))
            ->assertForbidden()
            ->assertSee("You can't open this album")
            ->assertSee(route('home'))
            ->assertDontSee('Secret plans');
    });

    it('hides the photos of albums trashed with their parent on the site', function () {
        $camp = frontendPublicAlbum();
        $day = frontendPublicAlbum('Day 3', ['parent_id' => $camp->id]);

        $camp->delete();

        $this->get(route('albums.show', $day))->assertNotFound();
    });
});

describe('a share link', function () {
    it('opens an unlisted album without listing it, also while public albums are off', function (bool $publicAlbums) {
        applySiteSettings([SiteSettings::PUBLIC_ALBUMS => $publicAlbums]);
        $album = Album::factory()->visibility(Visibility::Unlisted)->create();

        expect($album->share_token)->not->toBeNull();

        $this->get(route('albums.shared', $album->share_token))->assertOk()->assertSee($album->title);
        $this->get(route('albums.show', $album))->assertOk();
        $this->get(route('home'))->assertDontSee($album->title);
    })->with([
        'public albums on' => true,
        'public albums off' => false,
    ]);

    it('stops working when the album is no longer unlisted, and an earlier link ends when it gets a new one', function () {
        $album = Album::factory()->visibility(Visibility::Unlisted)->create();
        $token = $album->share_token;
        $this->get(route('albums.shared', $token))->assertOk();

        $album->update(['visibility' => Visibility::Private]);

        expect($album->fresh()->share_token)->toBeNull();
        $this->get(route('albums.shared', $token))->assertNotFound();

        $album->update(['visibility' => Visibility::Unlisted]);

        $this->get(route('albums.show', $album->fresh()))->assertNotFound();
        $this->get(route('albums.shared', $album->fresh()->share_token))->assertOk();
        $this->get(route('albums.show', $album->fresh()))->assertOk();
    });

    it('lets the owner copy the share link of an unlisted album, but not its visitors', function () {
        $uploader = userWithRole(Role::Uploader);
        $album = Album::factory()->for($uploader, 'owner')->visibility(Visibility::Unlisted)->create();
        $shareUrl = route('albums.shared', $album->share_token);

        $this->actingAs($uploader)
            ->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee('Copy share link')
            ->assertSee($album->share_token);

        auth()->logout();
        $this->get($shareUrl);
        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertDontSee('Copy share link');
    });

    it('ends with the end date, like public access, but not direct access', function () {
        $this->travelTo(now());
        $public = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Old summer', 'access_expires_at' => now()->addDay()]);
        $link = Album::factory()->visibility(Visibility::Unlisted)->create(['access_expires_at' => now()->addDay()]);
        $member = User::factory()->create();
        $restricted = Album::factory()->visibility(Visibility::Restricted)->create(['access_expires_at' => now()->addDay()]);
        $restricted->sharedUsers()->attach($member);

        $this->get(route('home'))->assertSee('Old summer');
        $this->travel(2)->days();

        $this->get(route('home'))->assertDontSee('Old summer');
        $this->get(route('albums.show', $public))->assertNotFound();
        $this->get(route('albums.shared', $link->share_token))->assertStatus(410)->assertSee('This link has expired');
        $this->actingAs($public->owner)->get(route('albums.show', $public))->assertOk();
        $this->actingAs($member)->get(route('albums.show', $restricted))->assertOk();
    });
});

describe('a password-protected album', function () {
    it('asks visitors for the password before showing the album and its photos', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create(['password' => 'lake-2026']);
        $thumb = route('media.show', [galleryPhoto($album, name: 'Lake.jpg', width: 160, height: 120)->original(), 'thumb']);

        $this->get(route('albums.show', $album))->assertStatus(401)->assertSee('Enter its password');
        $this->get($thumb)->assertNotFound();

        $this->post(route('albums.unlock', $album), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post(route('albums.unlock', $album), ['password' => 'lake-2026'])->assertRedirect(route('albums.show', $album));

        $this->get(route('albums.show', $album))->assertOk()->assertSee($thumb, false);
        $this->get($thumb)->assertOk();
    });

    it('never asks the owner or people with direct access', function () {
        $album = Album::factory()->visibility(Visibility::Restricted)->create(['password' => 'secret']);
        $member = frontendAlbumViewer('person it is shared with', $album);

        $this->actingAs($album->owner)->get(route('albums.show', $album))->assertOk();
        $this->actingAs($member)->get(route('albums.show', $album))->assertOk();
    });

    it('locks visitors out again when the password changes', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create(['password' => 'first']);
        $this->post(route('albums.unlock', $album), ['password' => 'first']);
        $this->get(route('albums.show', $album))->assertOk();

        $album->update(['password' => 'second']);

        $this->get(route('albums.show', $album))->assertStatus(401);
    });

    it('only gives the viewer the photos of albums the visitor may open', function () {
        $private = Album::factory()->create();
        $locked = Album::factory()->visibility(Visibility::Public)->create(['password' => 'lake-2026']);

        $this->getJson(route('albums.photos', $private))->assertNotFound();
        $this->getJson(route('albums.photos', $locked))->assertNotFound();

        $this->post(route('albums.unlock', $locked), ['password' => 'lake-2026']);
        $this->getJson(route('albums.photos', $locked))->assertOk();
    });
});

describe('the photos on the album page', function () {
    it('shows photos in the justified grid at their real aspect ratio, with description and date taken', function () {
        $album = frontendPublicAlbum();
        tap(galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600))
            ->update(['description' => 'Sunset over the lake', 'taken_at' => '2026-07-14 20:30:00']);

        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee('og-justified', false)
            ->assertSee('--r: 1.3333', false)
            ->assertSee('Sunset over the lake')
            ->assertSee('14.07.2026');
    });

    it('shows photos in the order chosen for the album', function () {
        $album = frontendPublicAlbum();
        galleryPhoto($album, name: 'Banana.jpg', width: 160, height: 120);
        galleryPhoto($album, name: 'Cherry.jpg', width: 160, height: 120);
        galleryPhoto($album, name: 'Apple.jpg', width: 160, height: 120);

        $album->update(['photo_order' => PhotoOrder::TitleAsc]);
        $this->get(route('albums.show', $album))->assertSeeInOrder(['Apple', 'Banana', 'Cherry']);

        $album->update(['photo_order' => PhotoOrder::TitleDesc]);
        $this->get(route('albums.show', $album))->assertSeeInOrder(['Cherry', 'Banana', 'Apple']);
    });

    it('loads the first photos of a page at once and the rest when scrolled to', function () {
        $album = frontendPublicAlbum();
        $subAlbum = frontendPublicAlbum('Day 3', ['parent_id' => $album->id]);
        galleryPhoto($subAlbum, name: 'Holiday Beach.jpg', width: 800, height: 600);
        foreach (range(1, 6) as $i) {
            galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600);
        }

        $response = $this->get(route('albums.show', $album));

        // One sub-album tile and six photos: the first six images load at once, the tile (first on the page) with high priority.
        $html = $response->assertOk()->getContent();
        expect(substr_count($html, 'loading="eager"'))->toBe(6);
        expect(substr_count($html, 'loading="lazy"'))->toBe(1);
        $response->assertSeeInOrder([route('albums.show', $subAlbum), 'fetchpriority="high"', 'og-justified', 'fetchpriority="auto"'], false);
    });

    it('lets the browser choose between the small and the large thumbnail by their real widths', function (int $width, int $height, string $expected) {
        $album = frontendPublicAlbum();
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
        $album = frontendPublicAlbum();
        $media = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600)->getFirstMedia(Photo::COLLECTION);
        $media->markAsConversionNotGenerated('small');

        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee(route('media.show', [$media, 'thumb']), false)
            ->assertDontSee('srcset=', false);
    });

    it('splits many albums inside an album into pages', function () {
        $camp = frontendPublicAlbum();
        foreach (range(1, 25) as $i) {
            frontendPublicAlbum(sprintf('Day %02d', $i), ['parent_id' => $camp->id, 'sort_order' => $i]);
        }

        $this->get(route('albums.show', $camp))
            ->assertSee('Day 24')->assertDontSee('Day 25');
        $this->get(route('albums.show', [$camp, 'albums' => 2]))
            ->assertSee('Day 25')->assertDontSee('Day 24');
    });
});

describe('the lightbox', function () {
    it('numbers photos across pages, links to the neighbouring pages and loads their photos', function () {
        $album = frontendPublicAlbum();
        $photos = collect(range(1, 3))->map(fn () => photoOnFreshDisk($album));

        $first = PhotoGridItems::from($album->photos()->paginate(2, page: 1))->pluck('lightbox');
        $second = PhotoGridItems::from($album->photos()->paginate(2, page: 2))->pluck('lightbox');

        expect($first->pluck('position')->all())->toBe([1, 2])
            ->and($first->pluck('total')->all())->toBe([3, 3])
            ->and($first[0])->not->toHaveKey('previousPage')
            ->and($first[1]['nextPage'])->toEndWith('?page=2#photo-first')
            ->and($second[0]['position'])->toBe(3)
            ->and($second[0]['id'])->toBe($photos[2]->id)
            ->and($second[0]['previousPage'])->toEndWith('?page=1#photo-last')
            ->and($second[0])->not->toHaveKey('nextPage')
            ->and($first[1]['nextItems'])->toBe(route('albums.photos', [$album, 'page' => 2]))
            ->and($second[0]['previousItems'])->toBe(route('albums.photos', [$album, 'page' => 1]))
            ->and($first[0])->not->toHaveKey('previousItems')
            ->and($second[0]['page'])->not->toBe($first[0]['page']);

        $this->getJson(route('albums.photos', [$album, 'page' => 1]))
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->photos()->first()->id)
            ->assertJsonPath('items.0.position', 1)
            ->assertJsonPath('items.0.page', route('albums.show', [$album, 'page' => 1]));
    });

    it('hands the slideshow and information settings to the viewer on the album page', function (bool $enabled) {
        $album = frontendPublicAlbum();
        galleryPhoto($album);
        Setting::put(SiteSettings::SLIDESHOW_SECONDS, 9);
        Setting::put(SiteSettings::SLIDESHOW_ENABLED, $enabled);
        Setting::put(SiteSettings::PHOTO_INFO_ENABLED, $enabled);

        $config = Js::from(['slideshow' => $enabled, 'seconds' => 9, 'info' => $enabled]);

        $this->get(route('albums.show', $album))->assertOk()->assertSee('configure('.$config.')', false);
    })->with([
        'on' => true,
        'off' => false,
    ]);

    it('gives the camera details in a readable form and never the location', function (array $exif, array $expected) {
        $photo = photoWithCameraData($exif);

        $lightbox = PhotoGridItems::from([$photo])[0]['lightbox'];

        expect($lightbox['info'])->toBe($expected)
            ->and(PhotoExif::details($photo))->toBe($expected)
            ->and(json_encode($lightbox))->not->toContain('GPS')->not->toContain('52/1');
    })->with([
        'full camera data with a position' => [
            [
                'Make' => 'Acme',
                'Model' => 'Pro 5',
                'LensModel' => '50mm F1.8',
                'FocalLength' => '50/1',
                'FNumber' => '28/10',
                'ExposureTime' => '1/250',
                'ISOSpeedRatings' => 400,
                'GPSLatitude' => ['52/1', '31/1', '0/1'],
                'GPSLongitude' => ['13/1', '24/1', '0/1'],
            ],
            [
                ['label' => 'Make', 'value' => 'Acme'],
                ['label' => 'Camera', 'value' => 'Pro 5'],
                ['label' => 'Lens', 'value' => '50mm F1.8'],
                ['label' => 'Focal length', 'value' => '50 mm'],
                ['label' => 'Aperture', 'value' => 'f/2.8'],
                ['label' => 'Exposure', 'value' => '1/250 s'],
                ['label' => 'ISO', 'value' => '400'],
            ],
        ],
        'a make beside the position' => [
            ['Make' => 'Acme', 'GPSLatitude' => ['52/1', '31/1', '0/1'], 'GPSLatitudeRef' => 'N'],
            [['label' => 'Make', 'value' => 'Acme']],
        ],
        'no camera data' => [[], []],
    ]);

    it('shows exposures of a second or more in seconds', function () {
        expect(PhotoExif::value('ExposureTime', '2/1'))->toBe('2 s')
            ->and(PhotoExif::value('ExposureTime', '5/10'))->toBe('1/2 s');
    });

    it('leaves the information out while the setting is off', function () {
        Setting::put(SiteSettings::PHOTO_INFO_ENABLED, false);
        $photo = photoWithCameraData(['Make' => 'Acme']);

        expect(PhotoGridItems::from([$photo])[0]['lightbox']['info'])->toBeNull();
    });

    it('carries the heart and whether the photo is liked for logged-in people', function () {
        $user = User::factory()->create();
        $liked = favoritesPublicPhoto();
        $other = favoritesPublicPhoto();
        $user->favoritePhotos()->attach($liked);

        $items = $this->actingAs($user)
            ->getJson(route('albums.photos', $liked->albums()->first()))
            ->assertOk()
            ->json('items');

        expect($items[0]['favorite'])->toBe(['url' => route('favorites.toggle', $liked), 'active' => true])
            ->and(PhotoGridItems::from([$other])[0]['lightbox']['favorite'])->toBe(['url' => route('favorites.toggle', $other), 'active' => false]);
    });

    it('has no heart for guests or while the setting is off', function () {
        $photo = favoritesPublicPhoto();

        expect(PhotoGridItems::from([$photo])[0]['lightbox']['favorite'])->toBeNull();

        Setting::put(SiteSettings::FAVORITES_ENABLED, false);
        $this->actingAs(User::factory()->create());

        expect(PhotoGridItems::from([$photo])[0]['lightbox']['favorite'])->toBeNull();
    });
});

describe('downloads', function () {
    it('keeps downloads from visitors while the switch is off, even where the album allows them', function () {
        $photo = downloadablePhotoInPublicAlbum();
        $album = $photo->albums()->first();

        expect(SiteSettings::downloadsEnabled())->toBeFalse();
        $this->get(route('media.show', [$photo->original(), 'preview']))->assertOk();
        $this->get(route('media.show', ['media' => $photo->original(), 'download' => 1]))->assertNotFound();
        $this->get(route('albums.download', $album))->assertNotFound();
        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertDontSee(route('albums.download', $album))
            ->assertDontSee('download=1');
        expect(downloadLightboxItem($photo)['download'])->toBeNull()
            ->and(AlbumAccess::canDownload(null, $photo))->toBeFalse();
    });

    it('offers the originals and the album zip where the album allows downloads', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp', 'allow_downloads' => true]);
        $photo = galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600);
        galleryPhoto($album, name: 'Holiday Beach.jpg', width: 800, height: 600);

        expect(downloadLightboxItem($photo)['download'])->toBe(route('media.show', ['media' => $photo->original(), 'download' => 1]));
        $this->get(route('albums.show', $album))->assertSee(route('albums.download', $album));
        $response = $this->get(route('albums.download', $album));

        $response->assertOk()->assertDownload('summer-camp.zip');
        expect(zipEntries($response))->toBe(['holiday-beach (1).jpg', 'holiday-beach.jpg']);
    });

    it('offers nothing where the album does not allow downloads, and keeps the zip from a locked album', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $closed = Album::factory()->visibility(Visibility::Public)->create();
        $photo = galleryPhoto($closed, name: 'Holiday Beach.jpg', width: 800, height: 600);
        $locked = Album::factory()->visibility(Visibility::Public)->create(['allow_downloads' => true, 'password' => 'lake-2026']);
        galleryPhoto($locked, name: 'Holiday Beach.jpg', width: 800, height: 600);

        expect(downloadLightboxItem($photo)['download'])->toBeNull();
        $this->get(route('albums.show', $closed))->assertDontSee(route('albums.download', $closed));
        $this->get(route('albums.download', $closed))->assertNotFound();
        $this->get(route('albums.download', $locked))->assertRedirect(route('albums.show', $locked));
    });

    it('takes the position out of album zips for visitors', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
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
});

describe('link previews', function () {
    beforeEach(fn () => Storage::fake('local'));

    it('describes a public album with its cover, which guests may load', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Harbour walk', 'description' => '<b>Boats</b> at dawn.']);
        $photo = galleryPhoto($album, name: 'Harbour.jpg', width: 1600, height: 1200);

        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Harbour walk">', false)
            ->assertSee('<meta name="description" content="Boats at dawn.">', false)
            ->assertSee('<meta property="og:image" content="'.photoThumbUrl($photo).'">', false)
            ->assertSee('<meta property="og:image:width" content="720">', false)
            ->assertSee('<meta property="og:image:height" content="540">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<link rel="canonical" href="'.route('albums.show', $album).'">', false)
            ->assertDontSee('noindex');

        $this->get(photoThumbUrl($photo))->assertOk();
    });

    it('shows an unlisted album at its share link, with a blurred card any link holder can load, kept out of search engines', function () {
        $album = Album::factory()->visibility(Visibility::Unlisted)->create(['title' => 'Our wedding']);
        $photo = galleryPhoto($album, name: 'Harbour.jpg', width: 1600, height: 1200);
        $cardUrl = route('albums.shared.cover', $album->share_token);

        $this->get(route('albums.shared', $album->share_token))
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Our wedding">', false)
            ->assertSee('<meta property="og:image" content="'.$cardUrl.'">', false)
            ->assertSee('<meta property="og:image:type" content="image/jpeg">', false)
            ->assertSee('<meta property="og:url" content="'.route('albums.shared', $album->share_token).'">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        // Preview apps fetch the image without the session the link unlocked.
        $this->flushSession();
        $this->get(photoThumbUrl($photo))->assertNotFound();
        expectPreviewCard($this->get($cardUrl), AlbumPreviewImage::LINK);
    });

    it('shows a lock card for a password-protected album, at its share link or its address', function (Visibility $visibility) {
        $album = Album::factory()->visibility($visibility)->create(['title' => 'Family', 'password' => 'secret']);
        $photo = galleryPhoto($album, name: 'Harbour.jpg', width: 1600, height: 1200);
        $cardUrl = $visibility === Visibility::Unlisted ? route('albums.shared.cover', $album->share_token) : route('albums.cover', $album);

        $this->get($visibility === Visibility::Unlisted ? route('albums.shared', $album->share_token) : route('albums.show', $album))
            ->assertUnauthorized()
            ->assertSee('<meta property="og:title" content="Family">', false)
            ->assertSee('<meta property="og:image" content="'.$cardUrl.'">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee(photoThumbUrl($photo));

        $this->flushSession();
        expectPreviewCard($this->get($cardUrl), AlbumPreviewImage::LOCK);
    })->with([
        'public' => Visibility::Public,
        'unlisted' => Visibility::Unlisted,
    ]);

    it('makes no card for albums a guest could not open', function () {
        $private = Album::factory()->visibility(Visibility::Private)->create(['password' => 'secret']);
        $open = Album::factory()->visibility(Visibility::Public)->create();
        $ended = Album::factory()->visibility(Visibility::Unlisted)->create(['access_expires_at' => now()->subDay()]);
        foreach ([$private, $open, $ended] as $album) {
            galleryPhoto($album, name: 'Harbour.jpg', width: 1600, height: 1200);
        }

        $this->get(route('albums.cover', $private))->assertNotFound();
        $this->get(route('albums.cover', $open))->assertNotFound();
        $this->get(route('albums.shared.cover', $ended->share_token))->assertNotFound();
        $this->get(route('albums.shared.cover', 'not-a-token'))->assertNotFound();
    });

    it('makes a new card for a new cover and removes the cards of a deleted album', function () {
        $album = Album::factory()->visibility(Visibility::Unlisted)->create();
        galleryPhoto($album, name: 'Harbour.jpg', width: 1600, height: 1200);
        $first = AlbumPreviewImage::path($album, AlbumPreviewImage::LINK);

        $album->update(['cover_photo_id' => galleryPhoto($album, name: 'Harbour.jpg', width: 1600, height: 1200)->id]);
        $second = AlbumPreviewImage::path($album->fresh(), AlbumPreviewImage::LINK);

        expect($second)->not->toBe($first)
            ->and(Storage::disk('local')->files(AlbumPreviewImage::DIRECTORY))->toBe([$second]);

        AlbumDeletion::forceDelete($album, false, $album->owner);

        expect(Storage::disk('local')->files(AlbumPreviewImage::DIRECTORY))->toBe([]);
    });

    it('keeps paragraphs apart in a description', function () {
        expect(PageMeta::excerpt('<p>First.</p><p>Second<br>line.</p>'))->toBe('First. Second line.')
            ->and(PageMeta::excerpt('<p> </p>'))->toBeNull();
    });
});

describe('the buttons on the album page', function () {
    it('gives the people who may edit the album buttons to add photos and edit it, and nobody else', function (string $viewer, bool $managesAlbum) {
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $this->actingAs(frontendAlbumViewer($viewer, $album));

        $response = $this->get(route('albums.show', $album))->assertOk();

        $managesAlbum
            ? $response->assertSee(PhotosRelationManager::addPhotosUrl($album))->assertSee(AlbumResource::getUrl('edit', ['record' => $album]))
            : $response->assertDontSee(AlbumResource::getUrl('edit', ['record' => $album]))->assertDontSee('Add photos');
    })->with([
        'the owner' => ['owner who uploads', true],
        'staff who may edit every album' => ['moderator', true],
        'a person it is only shared with' => ['person it is shared with', false],
    ]);

    it('links collaborators to the admin, and shows no management link to other visitors', function () {
        $album = Album::factory()->create();
        $member = addCollaborator($album, CollaboratorRole::Editor);

        $this->actingAs($member)->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee(__('Manage album'))
            ->assertSee(AlbumResource::getUrl('edit', ['record' => $album]), false)
            ->assertDontSee('ogPhotoUploader')
            ->assertDontSee('Leave album');

        $public = frontendPublicAlbum();
        $collaborator = addCollaborator($public, CollaboratorRole::Contributor, User::factory()->create(['name' => 'Zora Collaborator']));

        $this->actingAs(User::factory()->create())->get(route('albums.show', $public))
            ->assertOk()
            ->assertDontSee(__('Manage album'))
            ->assertDontSee($collaborator->name);
    });
});
