<?php

use App\Enums\CollaboratorRole;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\TagSettings;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Photos\Pages\EditPhoto;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Livewire\Livewire;

describe('tag names', function () {
    it('treats case and extra spaces as the same tag and keeps other spellings apart', function () {
        $album = Album::factory()->create();

        Tagging::sync($album, ['Pet 1', 'pet  1', 'PET 1', 'pet1'], User::factory()->create());

        expect(Tagging::namesOf($album))->toBe(['Pet 1', 'pet1'])
            ->and(Tag::count())->toBe(2);
    });

    it('reuses an existing tag in its existing spelling', function () {
        $existing = Tag::factory()->create(['name' => 'Summer Camp']);
        $album = Album::factory()->create();

        Tagging::sync($album, ['summer  camp'], User::factory()->create());

        expect($album->tags()->sole()->id)->toBe($existing->id)
            ->and(Tagging::namesOf($album))->toBe(['Summer Camp']);
    });

    it('rejects names without a letter or number and names that are too long', function (string $name) {
        expect(Tagging::problemWith([$name], null))->not->toBeNull();
    })->with(['only symbols' => '!!!', 'too long' => fn () => str_repeat('a', Tag::MAX_LENGTH + 1)]);

    it('removes a tag nothing uses any more but keeps one on an album in the trash', function () {
        $user = User::factory()->create();
        $album = tagged(Album::factory()->create(), 'typo');
        $trashed = tagged(Album::factory()->create(), 'kept');
        $other = tagged(Album::factory()->create(), 'kept');
        $trashed->delete();

        Tagging::sync($album, [], $user);
        Tagging::sync($other, [], $user);

        expect(Tag::pluck('name')->all())->toBe(['kept']);
    });
});

describe('editing tags', function () {
    it('adds tags when an album is created and removes them when it is edited', function () {
        actingAsRole();

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Holiday', 'tag_names' => ['Beach', 'Family']])
            ->call('create')
            ->assertHasNoFormErrors();

        $album = Album::where('title', 'Holiday')->sole();
        expect(Tagging::namesOf($album))->toBe(['Beach', 'Family']);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertSchemaStateSet(['tag_names' => ['Beach', 'Family']])
            ->fillForm(['tag_names' => ['Beach']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Tagging::namesOf($album))->toBe(['Beach']);
    });

    it('lets the uploader tag a photo', function () {
        $uploader = adminAccessUser();
        $photo = Photo::factory()->inAlbum(Album::factory()->for($uploader, 'owner')->create())->create(['uploader_id' => $uploader->id]);
        $this->actingAs($uploader);

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->fillForm(['tag_names' => ['Sunset']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Tagging::namesOf($photo))->toBe(['Sunset']);
    });

    it('does not let a contributor change an album\'s tags', function () {
        $album = tagged(Album::factory()->create(), 'Original');
        $contributor = addCollaborator($album, CollaboratorRole::Contributor, adminAccessUser());
        $this->actingAs($contributor);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->set('data.tag_names', ['Changed'])
            ->call('save')
            ->assertForbidden();

        expect(Tagging::namesOf($album))->toBe(['Original']);
    });

    it('records tag changes in the activity log', function () {
        actingAsRole();
        $album = Album::factory()->create();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tag_names' => ['Beach']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(ActivityEvent::where('subject_id', $album->id)->latest('id')->first()->properties['changes']['Tags']['added'])->toBe(['Beach']);
    });

    it('shows a photo only the tags of albums the viewer may open', function () {
        $viewer = User::factory()->create();
        $public = tagged(Album::factory()->visibility(Visibility::Public)->create(), 'Open');
        $private = tagged(Album::factory()->visibility(Visibility::Private)->create(), 'Secret');
        $photo = Photo::factory()->create();
        $public->addPhotos($photo);
        $private->addPhotos($photo);

        expect(Tagging::inheritedNames($photo, $viewer))->toBe(['Open']);
    });
});

describe('suggestions', function () {
    it('offers predefined, own and reachable tags while global suggestions are off', function () {
        $user = User::factory()->create();
        Setting::put(SiteSettings::TAGS_PREDEFINED, ['Predefined']);
        tagged(Album::factory()->for($user, 'owner')->create(), 'Mine');
        tagged(Album::factory()->visibility(Visibility::Public)->create(), 'Public');
        $shared = tagged(Album::factory()->visibility(Visibility::Restricted)->create(), 'Shared');
        $shared->sharedUsers()->attach($user);
        tagged(Album::factory()->visibility(Visibility::Private)->create(), 'Private');
        tagged(Album::factory()->visibility(Visibility::Public)->create(['password' => 'secret']), 'Protected');

        expect(Tagging::suggestionsFor($user))->toBe(['Mine', 'Predefined', 'Public', 'Shared']);
    });

    it('offers every tag in use with global suggestions on', function () {
        $user = User::factory()->create();
        Setting::put(SiteSettings::TAGS_GLOBAL_SUGGESTIONS, true);
        tagged(Album::factory()->visibility(Visibility::Private)->create(), 'Private');
        Tag::factory()->create(['name' => 'Unused']);

        expect(Tagging::suggestionsFor($user))->toBe(['Private']);
    });

    it('offers only predefined tags while new tags are not allowed', function () {
        $user = User::factory()->create();
        Setting::put(SiteSettings::TAGS_ALLOW_CUSTOM, false);
        Setting::put(SiteSettings::TAGS_PREDEFINED, ['Predefined']);
        tagged(Album::factory()->for($user, 'owner')->create(), 'Mine');

        expect(Tagging::suggestionsFor($user))->toBe(['Predefined']);
    });

    it('shows the existing tags in the tag field', function () {
        actingAsRole();
        Setting::put(SiteSettings::TAGS_PREDEFINED, ['Predefined one']);

        Livewire::test(CreateAlbum::class)
            ->assertSee('Predefined one')
            ->assertSee('Search or add a tag');
    });
});

describe('tags passed on to sub-albums', function () {
    it('is saved with the album form', function () {
        actingAsRole();
        $album = Album::factory()->create();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tags_apply_to_sub_albums' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->fresh()->tags_apply_to_sub_albums)->toBeTrue();
    });

    it('can be changed by an Editor', function () {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor, adminAccessUser()));

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tags_apply_to_sub_albums' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->fresh()->tags_apply_to_sub_albums)->toBeTrue();
    });

    it('finds albums and photos at every level below when turned on', function () {
        [$top, $bottom, $photo] = albumTree(true);

        $response = $this->get(route('home', ['q' => 'family']));

        expect(foundAlbums($response))->toContain($top->id, $bottom->id)
            ->and(foundPhotos($response))->toBe([$photo->id]);
    });

    it('keeps the tags to the album and its own photos when turned off', function () {
        [$top, , $photo] = albumTree(false);

        $response = $this->get(route('home', ['q' => 'family']));

        expect(foundAlbums($response))->toBe([$top->id])
            ->and(foundPhotos($response))->not->toContain($photo->id);
    });

    it('does not pass on tags from behind a password until it is entered', function () {
        [$top, $bottom, $photo] = albumTree(true, ['password' => 'secret']);

        $locked = $this->get(route('home', ['q' => 'family']));
        expect(foundAlbums($locked))->toBe([])->and(foundPhotos($locked))->toBe([]);

        $this->post(route('albums.unlock', $top), ['password' => 'secret']);

        $open = $this->get(route('home', ['q' => 'family']));
        expect(foundAlbums($open))->toContain($bottom->id)->and(foundPhotos($open))->toBe([$photo->id]);
    });

    it('lists passed-on tags for albums and photos, only from albums the viewer may open', function () {
        $viewer = User::factory()->create();
        [, $bottom, $photo] = albumTree(true);
        [, $hiddenBottom] = albumTree(false);
        $private = tagged(Album::factory()->visibility(Visibility::Private)->create(['tags_apply_to_sub_albums' => true]), 'Secret');
        $inside = Album::factory()->visibility(Visibility::Public)->create(['parent_id' => $private->id]);

        expect(Tagging::namesFromParents($bottom, $viewer))->toBe(['Family'])
            ->and(Tagging::inheritedNames($photo, $viewer))->toBe(['Family'])
            ->and(Tagging::namesFromParents($hiddenBottom, $viewer))->toBe([])
            ->and(Tagging::namesFromParents($inside, $viewer))->toBe([]);
    });
});

describe('settings', function () {
    it('is on with private suggestions and free tags by default', function () {
        expect(SiteSettings::taggingEnabled())->toBeTrue()
            ->and(SiteSettings::allowsCustomTags())->toBeTrue()
            ->and(SiteSettings::globalTagSuggestions())->toBeFalse()
            ->and(SiteSettings::predefinedTags())->toBe([]);
    });

    it('saves the settings and the predefined tags without duplicates', function () {
        actingAsRole();
        $existing = Tag::factory()->create(['name' => 'pet 1']);

        Livewire::test(TagSettings::class)
            ->fillForm([
                'allow_custom_tags' => true,
                'global_suggestions' => true,
                'predefined_tags' => ['Pet 1', 'PET  1', 'Nature'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(SiteSettings::globalTagSuggestions())->toBeTrue()
            ->and(SiteSettings::predefinedTags())->toBe(['Pet 1', 'Nature'])
            ->and($existing->fresh()->name)->toBe('Pet 1');
    });

    it('rejects an invalid predefined tag', function () {
        actingAsRole();

        Livewire::test(TagSettings::class)
            ->fillForm(['predefined_tags' => ['!!!']])
            ->call('save')
            ->assertHasFormErrors(['predefined_tags.0']);
    });

    it('is only for people who manage settings', function () {
        $this->actingAs(adminAccessUser());

        Livewire::test(TagSettings::class)->assertForbidden();
    });

    it('allows only predefined tags while new tags are off, and keeps tags already there', function () {
        actingAsRole();
        Setting::put(SiteSettings::TAGS_ALLOW_CUSTOM, false);
        Setting::put(SiteSettings::TAGS_PREDEFINED, ['Nature']);
        $album = tagged(Album::factory()->create(), 'Older tag');

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tag_names' => ['Older tag', 'Invented']])
            ->call('save')
            ->assertHasFormErrors(['tag_names']);

        expect(Tag::where('name', 'Invented')->exists())->toBeFalse();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tag_names' => ['Older tag', 'nature']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Tagging::namesOf($album->fresh()))->toBe(['Nature', 'Older tag']);
    });

    it('hides the field and ignores tags in the search while tagging is off, and keeps them', function () {
        actingAsRole();
        $album = tagged(Album::factory()->visibility(Visibility::Public)->create(['title' => 'Holiday']), 'Beach');
        Setting::put(SiteSettings::TAGGING, false);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertFormFieldHidden('tag_names')
            ->call('save')
            ->assertHasNoFormErrors();

        expect(foundAlbums($this->get(route('home', ['q' => 'Beach']))))->toBe([])
            ->and(Tagging::namesOf($album))->toBe(['Beach']);
    });
});

describe('search on the site', function () {
    it('finds albums by tag, also when written differently', function (string $term) {
        $album = tagged(Album::factory()->visibility(Visibility::Public)->create(['title' => 'Holiday']), 'Pet 1');
        Album::factory()->visibility(Visibility::Public)->create(['title' => 'Other']);

        expect(foundAlbums($this->get(route('home', ['q' => $term]))))->toBe([$album->id]);
    })->with(['exact' => 'Pet 1', 'lower case' => 'pet 1', 'no space' => 'pet1', 'part' => 'pet']);

    it('still finds albums by title', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Lake trip']);

        expect(foundAlbums($this->get(route('home', ['q' => 'lake']))))->toBe([$album->id]);
    });

    it('finds photos by their own tag and by their album\'s tag', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $own = tagged(Photo::factory()->inAlbum($album)->create(), 'Dog');
        Photo::factory()->inAlbum($album)->create();
        $taggedAlbum = tagged(Album::factory()->visibility(Visibility::Public)->create(), 'Dog');
        $inherited = Photo::factory()->inAlbum($taggedAlbum)->create();

        expect(foundPhotos($this->get(route('home', ['q' => 'dog']))))->toEqualCanonicalizing([$own->id, $inherited->id]);
    });

    it('stops finding a photo once it leaves the tagged album', function () {
        $album = tagged(Album::factory()->visibility(Visibility::Public)->create(), 'Dog');
        $photo = Photo::factory()->inAlbum($album)->create();
        $other = Album::factory()->visibility(Visibility::Public)->create();
        $other->addPhotos($photo);

        expect(foundPhotos($this->get(route('home', ['q' => 'dog']))))->toBe([$photo->id]);

        $album->photos()->detach($photo);

        expect(foundPhotos($this->get(route('home', ['q' => 'dog']))))->toBe([]);
    });

    it('never finds a photo through the tag of an album the visitor cannot open', function () {
        $private = tagged(Album::factory()->visibility(Visibility::Private)->create(), 'Secret');
        $public = Album::factory()->visibility(Visibility::Public)->create();
        $photo = Photo::factory()->create();
        $private->addPhotos($photo);
        $public->addPhotos($photo);

        $response = $this->get(route('home', ['q' => 'secret']));

        expect(foundPhotos($response))->toBe([])->and(foundAlbums($response))->toBe([]);
    });

    it('does not find private, unlisted or trashed content for guests', function (Visibility $visibility, bool $trashed) {
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
        $album = tagged(Album::factory()->visibility(Visibility::Public)->create(['title' => 'Locked', 'password' => 'secret']), 'Hidden');
        $photo = Photo::factory()->inAlbum($album)->create();

        $locked = $this->get(route('home', ['q' => 'hidden']));
        expect(foundAlbums($locked))->toBe([])->and(foundPhotos($locked))->toBe([]);

        $this->post(route('albums.unlock', $album), ['password' => 'secret']);

        $open = $this->get(route('home', ['q' => 'hidden']));
        expect(foundAlbums($open))->toBe([$album->id])->and(foundPhotos($open))->toBe([$photo->id]);
    });

    it('does not find a photo in an album inside a hidden album', function () {
        $hidden = Album::factory()->visibility(Visibility::Private)->create();
        $inside = tagged(Album::factory()->visibility(Visibility::Public)->create(['parent_id' => $hidden->id]), 'Nested');
        Photo::factory()->inAlbum($inside)->create();

        expect(foundPhotos($this->get(route('home', ['q' => 'nested']))))->toBe([]);
    });

    it('keeps the search when paging through the photos', function () {
        $album = tagged(Album::factory()->visibility(Visibility::Public)->create(), 'Dog');
        Photo::factory()->count(61)->inAlbum($album)->create();

        $photos = $this->get(route('home', ['q' => 'dog']))->viewData('photos');

        expect($photos->total())->toBe(61)
            ->and($photos->nextPageUrl())->toContain('q=dog')->toContain('photos=2');
    });
});
