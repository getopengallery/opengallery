<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\SiteSettings;
use App\Support\Tagging;

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

describe('what a viewer sees', function () {
    it('shows a photo only the tags of albums the viewer may open', function () {
        $viewer = User::factory()->create();
        $public = tagged(Album::factory()->visibility(Visibility::Public)->create(), 'Open');
        $private = tagged(Album::factory()->visibility(Visibility::Private)->create(), 'Secret');
        $photo = Photo::factory()->create();
        $public->addPhotos($photo);
        $private->addPhotos($photo);

        expect(Tagging::inheritedNames($photo, $viewer))->toBe(['Open']);
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
});

it('is on with private suggestions and free tags by default', function () {
    expect(SiteSettings::taggingEnabled())->toBeTrue()
        ->and(SiteSettings::allowsCustomTags())->toBeTrue()
        ->and(SiteSettings::globalTagSuggestions())->toBeFalse()
        ->and(SiteSettings::predefinedTags())->toBe([]);
});
