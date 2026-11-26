<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\SearchEngineSettings;
use App\Models\Album;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\AlbumDeletion;
use App\Support\AlbumPreviewImage;
use App\Support\PageMeta;
use App\Support\SearchEngines;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('gallery');
    Storage::fake('local');
});

describe('link previews', function () {
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
    })->with([Visibility::Public, Visibility::Unlisted]);

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

    it('describes a page with its text and the first photo placed on it', function () {
        $photo = galleryPhoto(Album::factory()->visibility(Visibility::Private)->create(), name: 'Harbour.jpg', width: 1600, height: 1200);
        $page = Page::factory()->create([
            'title' => 'About us',
            'body' => '<p>We &amp; our <em>cameras</em>.</p><div data-type="customBlock" data-id="photo" data-config="'.e(json_encode(['photo_id' => $photo->id])).'"></div>',
        ]);
        $imageUrl = route('pages.photo', [$page, $photo, 'thumb']);

        $this->get(route('pages.show', $page))
            ->assertOk()
            ->assertSee('<meta name="description" content="We &amp; our cameras.">', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:image" content="'.$imageUrl.'">', false);

        $this->get($imageUrl)->assertOk();
    });

    it('keeps members-only pages out of search engines', function () {
        $page = Page::factory()->membersOnly()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('pages.show', $page))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    });

    it('describes the start page with the site description and the app icon, escaped', function () {
        Setting::put(SiteSettings::DESCRIPTION, 'Photos by "us" <script>alert(1)</script>');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<meta name="description" content="Photos by &quot;us&quot; &lt;script&gt;alert(1)&lt;/script&gt;">', false)
            ->assertSee('<meta name="twitter:card" content="summary">', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    });
});

describe('robots.txt and sitemap', function () {
    it('lets search engines in, keeps AI crawlers and private areas out and points to the sitemap', function () {
        $robots = $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        expect($robots)->toContain("User-agent: GPTBot\n")
            ->toContain("User-agent: YouBot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\n")
            ->toContain('Sitemap: '.route('search.sitemap'))
            ->not->toContain('Googlebot');

        $this->get(route('home'))->assertSee('<meta name="robots" content="max-image-preview:large, noai, noimageai">', false);
    });

    it('lets AI crawlers in when the admin allows them, and adds the admin\'s own rules', function () {
        Setting::put(SiteSettings::BLOCK_AI_CRAWLERS, false);
        Setting::put(SiteSettings::ROBOTS_EXTRA, "User-agent: Example-Bot\r\nDisallow: /pages/");

        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        expect($robots)->not->toContain('GPTBot')
            ->toContain("User-agent: Example-Bot\nDisallow: /pages/\n\nSitemap:");

        $this->get(route('home'))->assertSee('<meta name="robots" content="max-image-preview:large">', false);
    });

    it('keeps search engines out when indexing is off, but not link preview apps', function () {
        Setting::put(SiteSettings::SEARCH_INDEXING, false);
        $album = Album::factory()->visibility(Visibility::Public)->create();

        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        expect($robots)->toContain("User-agent: *\nDisallow: /")
            ->toContain("User-agent: Twitterbot\n")
            ->not->toContain('Sitemap:');

        $this->get(route('search.sitemap'))->assertNotFound();
        $this->get(route('albums.show', $album))->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    });

    it('lists only what visitors without an account may open', function () {
        $public = Album::factory()->visibility(Visibility::Public)->create();
        $hidden = [
            Album::factory()->visibility(Visibility::Unlisted)->create(),
            Album::factory()->visibility(Visibility::Registered)->create(),
            Album::factory()->visibility(Visibility::Private)->create(),
            Album::factory()->visibility(Visibility::Public)->create(['password' => 'secret']),
            Album::factory()->visibility(Visibility::Public)->create(['access_expires_at' => now()->subDay()]),
        ];
        $page = Page::factory()->create();
        $hiddenPages = [Page::factory()->membersOnly()->create(), Page::factory()->draft()->create()];

        $response = $this->get(route('search.sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.route('home').'</loc>', false)
            ->assertSee('<loc>'.route('albums.show', $public).'</loc>', false)
            ->assertSee('<loc>'.route('pages.show', $page).'</loc>', false);

        foreach ($hidden as $album) {
            $response->assertDontSee(route('albums.show', $album));
        }
        foreach ($hiddenPages as $hiddenPage) {
            $response->assertDontSee(route('pages.show', $hiddenPage));
        }
        expect(simplexml_load_string($response->getContent()))->not->toBeFalse();
    });

    it('lists no albums while public albums are off', function () {
        Setting::put(SiteSettings::PUBLIC_ALBUMS, false);
        Setting::put(SiteSettings::GUEST_HOME, 'login');
        SiteSettings::apply();
        $album = Album::factory()->visibility(Visibility::Public)->create();

        $this->get(route('search.sitemap'))
            ->assertOk()
            ->assertDontSee(route('albums.show', $album))
            ->assertDontSee('<loc>'.route('home').'</loc>', false);
    });

    it('removes the old static robots.txt on upgrade, unless it was changed', function () {
        $public = sys_get_temp_dir().'/og-public-'.uniqid();
        mkdir($public);

        file_put_contents($public.'/robots.txt', SearchEngines::LEGACY_ROBOTS_FILE);
        expect(SearchEngines::removeLegacyRobotsFile($public))->toBeFalse()
            ->and(file_exists($public.'/robots.txt'))->toBeFalse();

        file_put_contents($public.'/robots.txt', "User-agent: *\nDisallow: /private\n");
        expect(SearchEngines::removeLegacyRobotsFile($public))->toBeTrue()
            ->and(file_exists($public.'/robots.txt'))->toBeTrue();

        unlink($public.'/robots.txt');
        rmdir($public);
    });
});

it('saves the search engine settings and adds the verification tags', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    Livewire::test(SearchEngineSettings::class)
        ->assertSee('User-agent: GPTBot')
        ->set('data.block_ai_crawlers', false)
        ->set('data.robots_extra', "User-agent: Example-Bot\nDisallow: /")
        ->assertDontSee('User-agent: GPTBot')
        ->assertSee('User-agent: Example-Bot')
        ->set('data.description', 'Our family photos.')
        ->set('data.google_verification', '<meta name="google-site-verification" content="abc123_XYZ" />')
        ->set('data.bing_verification', 'BING42')
        ->call('save')
        ->assertHasNoErrors();

    expect(SiteSettings::description())->toBe('Our family photos.')
        ->and(SiteSettings::blocksAiCrawlers())->toBeFalse()
        ->and(SiteSettings::robotsExtra())->toBe("User-agent: Example-Bot\nDisallow: /")
        ->and(SiteSettings::googleVerification())->toBe('abc123_XYZ');

    $this->get(route('home'))
        ->assertSee('<meta name="google-site-verification" content="abc123_XYZ">', false)
        ->assertSee('<meta name="msvalidate.01" content="BING42">', false)
        ->assertSee('<meta property="og:description" content="Our family photos.">', false);
});

it('keeps the verification codes while search engines are turned off', function () {
    Setting::put(SiteSettings::GOOGLE_VERIFICATION, 'abc123');
    Setting::put(SiteSettings::BING_VERIFICATION, 'BING42');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    Livewire::test(SearchEngineSettings::class)
        ->set('data.search_indexing', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(SiteSettings::searchIndexing())->toBeFalse()
        ->and(SiteSettings::googleVerification())->toBe('abc123')
        ->and(SiteSettings::bingVerification())->toBe('BING42');
});
