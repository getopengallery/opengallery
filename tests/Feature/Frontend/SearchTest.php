<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Support\SiteSettings;
use App\Support\Tagging;

describe('searching by title and description', function () {
    it('finds albums by title or description, also inside other albums', function () {
        $camp = frontendPublicAlbum('Summer camp');
        $day = frontendPublicAlbum('Day 3', ['description' => 'Canoeing on the lake', 'parent_id' => $camp->id]);
        frontendPublicAlbum('Winter hike');

        $this->get(route('home', ['q' => 'lake']))
            ->assertOk()
            ->assertSee('1 album matches "lake".')
            ->assertSee('Day 3')
            ->assertDontSee('Winter hike');
        $this->get(route('home', ['q' => 'CAMP']))
            ->assertSee('Summer camp')
            ->assertDontSee('Winter hike');

        expect(foundAlbums($this->get(route('home', ['q' => 'lake']))))->toBe([$day->id])
            ->and(foundAlbums($this->get(route('home', ['q' => 'summer']))))->toBe([$camp->id]);
    });

    it('leaves out albums the visitor may not see, and visible albums inside hidden ones', function () {
        $hidden = Album::factory()->create(['title' => 'Private lake trip']);
        frontendPublicAlbum('Lake day', ['parent_id' => $hidden->id]);
        Album::factory()->visibility(Visibility::Unlisted)->create(['title' => 'Lake party']);

        $this->get(route('home', ['q' => 'lake']))
            ->assertOk()
            ->assertSee('No albums match "lake".')
            ->assertDontSee('Private lake trip')
            ->assertDontSee('Lake day')
            ->assertDontSee('Lake party');
    });

    it('treats % and _ in a search as plain characters', function () {
        frontendPublicAlbum();

        $this->get(route('home', ['q' => '%']))
            ->assertOk()
            ->assertSee('No albums match "%".')
            ->assertDontSee('Summer camp');
    });

    it('ignores a search that is not plain text', function () {
        frontendPublicAlbum();

        $this->get('/?q[]=camp')->assertOk()->assertSee('Summer camp');
    });

    it('keeps the search when moving to the next page of results', function () {
        foreach (range(1, 25) as $i) {
            frontendPublicAlbum(sprintf('Camp %02d', $i));
        }

        $this->get(route('home', ['q' => 'camp']))
            ->assertSee('25 albums match "camp".')
            ->assertSee('q=camp&amp;page=2', false);
    });
});

describe('searching by tag', function () {
    it('finds albums by tag, also when written differently', function (string $term) {
        $album = tagged(frontendPublicAlbum('Holiday'), 'Pet 1');
        frontendPublicAlbum('Other');

        expect(foundAlbums($this->get(route('home', ['q' => $term]))))->toBe([$album->id]);
    })->with(['exact' => 'Pet 1', 'lower case' => 'pet 1', 'no space' => 'pet1', 'part' => 'pet']);

    it('finds photos by their own tag and by their album\'s tag', function () {
        $album = frontendPublicAlbum();
        $own = tagged(Photo::factory()->inAlbum($album)->create(), 'Dog');
        Photo::factory()->inAlbum($album)->create();
        $taggedAlbum = tagged(frontendPublicAlbum(), 'Dog');
        $inherited = Photo::factory()->inAlbum($taggedAlbum)->create();

        expect(foundPhotos($this->get(route('home', ['q' => 'dog']))))->toEqualCanonicalizing([$own->id, $inherited->id]);
    });

    it('stops finding a photo once it leaves the tagged album', function () {
        $album = tagged(frontendPublicAlbum(), 'Dog');
        $photo = Photo::factory()->inAlbum($album)->create();
        $other = frontendPublicAlbum();
        $other->addPhotos($photo);

        expect(foundPhotos($this->get(route('home', ['q' => 'dog']))))->toBe([$photo->id]);

        $album->photos()->detach($photo);

        expect(foundPhotos($this->get(route('home', ['q' => 'dog']))))->toBe([]);
    });

    it('never finds a photo through the tag of an album the visitor cannot open', function () {
        $private = tagged(Album::factory()->visibility(Visibility::Private)->create(), 'Secret');
        $public = frontendPublicAlbum();
        $photo = Photo::factory()->create();
        $private->addPhotos($photo);
        $public->addPhotos($photo);

        $response = $this->get(route('home', ['q' => 'secret']));

        expect(foundPhotos($response))->toBe([])->and(foundAlbums($response))->toBe([]);
    });

    it('does not find private, unlisted or trashed content', function (Visibility $visibility, bool $trashed) {
        $album = tagged(Album::factory()->visibility($visibility)->create(), 'Hidden');
        tagged(Photo::factory()->inAlbum($album)->create(), 'Hidden');

        if ($trashed) {
            $album->delete();
        }

        $response = $this->get(route('home', ['q' => 'hidden']));

        expect(foundAlbums($response))->toBe([])->and(foundPhotos($response))->toBe([]);
    })->with([
        'private' => [Visibility::Private, false],
        'unlisted' => [Visibility::Unlisted, false],
        'trashed' => [Visibility::Public, true],
    ]);

    it('keeps tags behind an album password until it is entered', function () {
        $album = tagged(frontendPublicAlbum('Locked', ['password' => 'secret']), 'Hidden');
        $photo = Photo::factory()->inAlbum($album)->create();

        $locked = $this->get(route('home', ['q' => 'hidden']));
        expect(foundAlbums($locked))->toBe([])->and(foundPhotos($locked))->toBe([]);

        $this->post(route('albums.unlock', $album), ['password' => 'secret']);

        $open = $this->get(route('home', ['q' => 'hidden']));
        expect(foundAlbums($open))->toBe([$album->id])->and(foundPhotos($open))->toBe([$photo->id]);
    });

    it('does not find a photo in an album inside a hidden album', function () {
        $hidden = Album::factory()->visibility(Visibility::Private)->create();
        $inside = tagged(frontendPublicAlbum(attributes: ['parent_id' => $hidden->id]), 'Nested');
        Photo::factory()->inAlbum($inside)->create();

        expect(foundPhotos($this->get(route('home', ['q' => 'nested']))))->toBe([]);
    });

    it('keeps the search when paging through the photos', function () {
        $album = tagged(frontendPublicAlbum(), 'Dog');
        Photo::factory()->count(61)->inAlbum($album)->create();

        $photos = $this->get(route('home', ['q' => 'dog']))->viewData('photos');

        expect($photos->total())->toBe(61)
            ->and($photos->nextPageUrl())->toContain('q=dog')->toContain('photos=2');
    });

    it('ignores tags in the search while tagging is off, and keeps them', function () {
        $album = tagged(frontendPublicAlbum('Holiday'), 'Beach');
        Setting::put(SiteSettings::TAGGING, false);

        expect(foundAlbums($this->get(route('home', ['q' => 'Beach']))))->toBe([])
            ->and(Tagging::namesOf($album))->toBe(['Beach']);
    });
});

describe('searching by tags passed on to sub-albums', function () {
    it('finds albums and photos at every level below only when turned on', function (bool $passesOn) {
        [$top, $bottom, $photo] = albumTree($passesOn);

        $response = $this->get(route('home', ['q' => 'family']));

        $passesOn
            ? expect(foundAlbums($response))->toContain($top->id, $bottom->id)
            : expect(foundAlbums($response))->toBe([$top->id]);
        expect(foundPhotos($response))->toBe($passesOn ? [$photo->id] : []);
    })->with(['turned on' => true, 'turned off' => false]);

    it('does not pass on tags from behind a password until it is entered', function () {
        [$top, $bottom, $photo] = albumTree(true, ['password' => 'secret']);

        $locked = $this->get(route('home', ['q' => 'family']));
        expect(foundAlbums($locked))->toBe([])->and(foundPhotos($locked))->toBe([]);

        $this->post(route('albums.unlock', $top), ['password' => 'secret']);

        $open = $this->get(route('home', ['q' => 'family']));
        expect(foundAlbums($open))->toContain($bottom->id)->and(foundPhotos($open))->toBe([$photo->id]);
    });
});
