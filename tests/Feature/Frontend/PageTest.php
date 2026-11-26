<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Page;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

describe('a page', function () {
    it('is shown to everyone once published, even with public albums turned off', function () {
        applySiteSettings([SiteSettings::PUBLIC_ALBUMS => false]);
        $page = Page::factory()->create(['title' => 'Imprint', 'body' => '<p>Example family, Example Street 1</p>']);

        $this->get(route('pages.show', $page))
            ->assertOk()
            ->assertSee('Imprint')
            ->assertSee('Example Street 1');
    });

    it('hides drafts, and keeps members-only pages from guests and out of search engines', function () {
        $draft = Page::factory()->draft()->create();
        $members = Page::factory()->membersOnly()->create();

        $this->get(route('pages.show', $draft))->assertNotFound();
        $this->get(route('pages.show', $members))->assertNotFound();
        $this->actingAs(User::factory()->create())
            ->get(route('pages.show', $members))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    });

    it('does not run scripts written into it', function () {
        $page = Page::factory()->create(['body' => '<p>Hello</p><script>alert(1)</script><img src=x onerror="alert(2)">']);

        $this->get(route('pages.show', $page))
            ->assertSee('Hello')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('onerror', false);
    });

    it('describes itself in link previews with its text and the first photo placed on it', function () {
        $photo = galleryPhoto(Album::factory()->visibility(Visibility::Private)->create(), name: 'Harbour.jpg', width: 1600, height: 1200);
        $page = Page::factory()->create([
            'title' => 'About us',
            'body' => '<p>We &amp; our <em>cameras</em>.</p>'.customBlockMarkup('photo', ['photo_id' => $photo->id]),
        ]);
        $imageUrl = route('pages.photo', [$page, $photo, 'thumb']);

        $this->get(route('pages.show', $page))
            ->assertOk()
            ->assertSee('<meta name="description" content="We &amp; our cameras.">', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:image" content="'.$imageUrl.'">', false);

        $this->get($imageUrl)->assertOk();
    });
});

describe('photos on a page', function () {
    it('show as a photo and a gallery, each opening the lightbox', function () {
        $album = Album::factory()->create();
        [$a, $b, $c] = [galleryPhoto($album, name: 'A.jpg', width: 300, height: 200), galleryPhoto($album, name: 'B.jpg', width: 200, height: 300), galleryPhoto($album, name: 'C.jpg', width: 300, height: 200)];
        $page = Page::factory()->create(['body' => '<p>Our summer.</p>'
            .customBlockMarkup('photo', ['photo_id' => $a->id, 'caption' => 'At the lake', 'width' => 'wide'])
            .customBlockMarkup('gallery', ['photo_ids' => [$c->id, $b->id], 'layout' => 'justified', 'caption' => 'More photos'])]);

        $this->get(route('pages.show', $page))
            ->assertOk()
            ->assertSee('Our summer.')
            ->assertSee('At the lake')
            ->assertSee('og-wide', false)
            ->assertSee(route('pages.photo', [$page, $a, 'preview']), false)
            ->assertSeeInOrder([route('pages.photo', [$page, $c, 'thumb']), route('pages.photo', [$page, $b, 'thumb'])], false)
            ->assertSee('--r: 0.6667', false)
            ->assertSee('ogLightbox', false)
            ->assertSee('More photos');
    });

    it('are served to the visitors of a published page, even from private albums, for their own browser only', function () {
        $private = Album::factory()->visibility(Visibility::Private)->create();
        $placed = galleryPhoto($private, name: 'Placed.jpg', width: 300, height: 200);
        $other = galleryPhoto($private, name: 'Other.jpg', width: 300, height: 200);
        $page = Page::factory()->create(['body' => customBlockMarkup('photo', ['photo_id' => $placed->id])]);

        // Not "public": no shared cache may keep serving it once the page is unpublished.
        $this->get(route('pages.photo', [$page, $placed, 'preview']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, private');
        $this->get(route('pages.photo', [$page, $other, 'preview']))->assertNotFound();
        $this->get(route('media.show', [$placed->original(), 'preview']))->assertNotFound();

        $page->update(['is_published' => false]);
        $this->get(route('pages.photo', [$page, $placed, 'preview']))->assertNotFound();
    });

    it('stay away from guests on members-only pages', function () {
        $photo = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg', width: 300, height: 200);
        $page = Page::factory()->membersOnly()->create(['body' => customBlockMarkup('gallery', ['photo_ids' => [$photo->id]])]);

        $this->get(route('pages.photo', [$page, $photo, 'thumb']))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('pages.photo', [$page, $photo, 'thumb']))->assertOk();
    });
});
