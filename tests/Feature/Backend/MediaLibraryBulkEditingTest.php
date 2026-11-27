<?php

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Http\Controllers\PhotoSelectionDownloadController;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

describe('filters', function () {
    beforeEach(fn () => $this->admin = actingAsRole(Role::Admin));

    it('narrows the library by tags and by having no tags, only while tagging is on', function () {
        $album = Album::factory()->create();
        $sunset = tagged(galleryPhoto($album, name: 'Sunset.jpg'), 'Sunset');
        $beach = tagged(galleryPhoto($album, name: 'Beach.jpg'), 'Beach');
        $bare = galleryPhoto($album, name: 'Bare.jpg');

        Livewire::test(ListPhotos::class)
            ->filterTable('tags', [(string) Tag::where('name', 'Sunset')->value('id')])
            ->assertCanSeeTableRecords([$sunset])
            ->assertCanNotSeeTableRecords([$beach, $bare])
            ->removeTableFilters()
            ->filterTable('tags', ['__empty'])
            ->assertCanSeeTableRecords([$bare])
            ->assertCanNotSeeTableRecords([$sunset, $beach]);

        Setting::put(SiteSettings::TAGGING, false);

        Livewire::test(ListPhotos::class)
            ->assertTableFilterHidden('tags');
    });

    it('narrows the library by license, only while licensing is on', function () {
        $album = Album::factory()->create();
        $first = galleryPhoto($album, name: 'First.jpg');
        $second = galleryPhoto($album, name: 'Second.jpg');

        Livewire::test(ListPhotos::class)->assertTableFilterHidden('license');

        enableLicensing();
        $first->update(['license_id' => packLicense('CC0 1.0')->id]);
        $second->update(['license_id' => packLicense('CC BY 4.0')->id]);

        Livewire::test(ListPhotos::class)
            ->assertTableFilterVisible('license')
            ->filterTable('license', packLicense('CC0 1.0')->id)
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second]);
    });

    it('narrows the library by date taken, shape, number of albums and file name', function () {
        $camp = Album::factory()->create();
        $best = Album::factory()->create();
        $wide = galleryPhoto($camp, name: 'Wide_shot.jpg', width: 300, height: 200);
        $wide->update(['taken_at' => '2024-05-10 12:00:00']);
        $tall = galleryPhoto($camp, name: 'Tall.jpg', width: 200, height: 300);
        $tall->update(['taken_at' => '2023-01-01 12:00:00']);
        $square = galleryPhoto($camp, name: 'Square.jpg', width: 200, height: 200);
        $best->addPhotos($square);

        Livewire::test(ListPhotos::class)
            ->filterTable('taken', ['from' => '2024-01-01', 'until' => '2024-12-31'])
            ->assertCanSeeTableRecords([$wide])
            ->assertCanNotSeeTableRecords([$tall, $square])
            ->removeTableFilters()
            ->filterTable('orientation', 'landscape')
            ->assertCanSeeTableRecords([$wide])
            ->assertCanNotSeeTableRecords([$tall, $square])
            ->filterTable('orientation', 'portrait')
            ->assertCanSeeTableRecords([$tall])
            ->assertCanNotSeeTableRecords([$wide, $square])
            ->filterTable('orientation', 'square')
            ->assertCanSeeTableRecords([$square])
            ->assertCanNotSeeTableRecords([$wide, $tall])
            ->removeTableFilters()
            ->filterTable('album_count', 'one')
            ->assertCanSeeTableRecords([$wide, $tall])
            ->assertCanNotSeeTableRecords([$square])
            ->filterTable('album_count', 'several')
            ->assertCanSeeTableRecords([$square])
            ->assertCanNotSeeTableRecords([$wide, $tall])
            ->removeTableFilters()
            ->filterTable('file_name', ['value' => ' SHOT '])
            ->assertCanSeeTableRecords([$wide])
            ->assertCanNotSeeTableRecords([$tall, $square])
            // "%" and "_" are plain characters, not wildcards.
            ->filterTable('file_name', ['value' => 'wide%'])
            ->assertCanNotSeeTableRecords([$wide, $tall, $square])
            ->filterTable('file_name', ['value' => 'wid_'])
            ->assertCanNotSeeTableRecords([$wide, $tall, $square]);
    });

    it('narrows the library by resolution and file type', function () {
        $album = Album::factory()->create();
        $small = galleryPhoto($album, name: 'Small.jpg');
        $medium = tap(galleryPhoto($album, name: 'Medium.jpg'))->update(['width' => 4000, 'height' => 3000]);
        $large = tap(galleryPhoto($album, name: 'Large.jpg'))->update(['width' => 5000, 'height' => 3000]);
        $png = galleryPhoto($album, name: 'Logo.png');
        $png->original()->update(['mime_type' => 'image/png']);
        $png->update(['width' => 100, 'height' => 100]);

        Livewire::test(ListPhotos::class)
            ->filterTable('resolution', 'small')
            ->assertCanSeeTableRecords([$small, $png])
            ->assertCanNotSeeTableRecords([$medium, $large])
            ->filterTable('resolution', 'medium')
            ->assertCanSeeTableRecords([$medium])
            ->assertCanNotSeeTableRecords([$small, $large, $png])
            ->filterTable('resolution', 'large')
            ->assertCanSeeTableRecords([$large])
            ->assertCanNotSeeTableRecords([$small, $medium, $png])
            ->removeTableFilters()
            ->filterTable('file_type', ['image/png'])
            ->assertCanSeeTableRecords([$png])
            ->assertCanNotSeeTableRecords([$small, $medium, $large])
            ->filterTable('file_type', ['image/png', 'image/jpeg'])
            ->assertCanSeeTableRecords([$small, $medium, $large, $png]);
    });

    it('finds photos that miss any of the chosen details, an empty title counting as missing, and offers the license only while licensing is on', function () {
        $album = Album::factory()->create();
        $details = ['title' => 'Title', 'description' => 'Text', 'taken_at' => '2024-05-10 12:00:00'];
        $noTitle = tap(galleryPhoto($album, name: 'A.jpg'))->update([...$details, 'title' => '']);
        $noDescription = tap(galleryPhoto($album, name: 'B.jpg'))->update([...$details, 'description' => null]);
        $noDate = tap(galleryPhoto($album, name: 'C.jpg'))->update([...$details, 'taken_at' => null]);
        $complete = tap(galleryPhoto($album, name: 'D.jpg'))->update($details);

        $page = Livewire::test(ListPhotos::class)
            ->filterTable('missing', ['title'])
            ->assertCanSeeTableRecords([$noTitle])
            ->assertCanNotSeeTableRecords([$noDescription, $noDate, $complete])
            ->filterTable('missing', ['title', 'taken_at'])
            ->assertCanSeeTableRecords([$noTitle, $noDate])
            ->assertCanNotSeeTableRecords([$noDescription, $complete])
            ->filterTable('missing', ['description', 'taken_at', 'title'])
            ->assertCanSeeTableRecords([$noTitle, $noDescription, $noDate])
            ->assertCanNotSeeTableRecords([$complete]);

        expect(array_keys($page->instance()->getTable()->getFilter('missing')->getOptions()))->toBe(['title', 'description', 'taken_at']);

        $default = enableLicensing();
        $complete->update(['license_id' => $default->id]);
        $unlicensed = tap(galleryPhoto($album, name: 'E.jpg'))->update([...$details, 'license_id' => null]);

        $page = Livewire::test(ListPhotos::class)
            ->filterTable('missing', ['license'])
            ->assertCanSeeTableRecords([$unlicensed])
            ->assertCanNotSeeTableRecords([$complete]);

        expect(array_keys($page->instance()->getTable()->getFilter('missing')->getOptions()))->toContain('license');
    });

    it('narrows the library by several albums, by photos in no album and by the viewer\'s own favorites', function () {
        $camp = Album::factory()->create();
        $best = Album::factory()->create();
        $other = Album::factory()->create();
        $inCamp = galleryPhoto($camp, name: 'Camp.jpg');
        $inBest = galleryPhoto($best, name: 'Best.jpg');
        $inOther = galleryPhoto($other, name: 'Other.jpg');
        $orphan = Photo::factory()->create();
        $this->admin->favoritePhotos()->attach([$inCamp->id, $orphan->id]);
        User::factory()->create()->favoritePhotos()->attach($inBest);

        Livewire::test(ListPhotos::class)
            ->filterTable('albums', [$camp->id, $best->id])
            ->assertCanSeeTableRecords([$inCamp, $inBest])
            ->assertCanNotSeeTableRecords([$inOther, $orphan])
            ->removeTableFilters()
            ->filterTable('album_count', 'none')
            ->assertCanSeeTableRecords([$orphan])
            ->assertCanNotSeeTableRecords([$inCamp, $inBest, $inOther])
            ->removeTableFilters()
            ->filterTable('favorites', ['isActive' => true])
            ->assertCanSeeTableRecords([$inCamp, $orphan])
            ->assertCanNotSeeTableRecords([$inBest, $inOther]);
    });
});

describe('tag bulk actions', function () {
    beforeEach(fn () => $this->admin = actingAsRole(Role::Admin));

    it('adds tags to the selected photos and keeps the tags they have', function () {
        $album = Album::factory()->create();
        $first = tagged(galleryPhoto($album, name: 'First.jpg'), 'Old');
        $second = galleryPhoto($album, name: 'Second.jpg');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('addTags')->table()->bulk(), ['tags' => ['Sunset', 'old']])
            ->assertHasNoFormErrors()
            ->assertNotified('Tags added to 2 photos');

        expect(Tagging::namesOf($first))->toBe(['Old', 'Sunset'])
            ->and(Tagging::namesOf($second))->toBe(['Old', 'Sunset'])
            ->and(Tag::count())->toBe(2);
    });

    it('adds only predefined tags while custom tags are off and refuses the others', function () {
        Setting::put(SiteSettings::TAGS_ALLOW_CUSTOM, false);
        Setting::put(SiteSettings::TAGS_PREDEFINED, ['Sunset']);
        $photo = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$photo->id])
            ->callAction(TestAction::make('addTags')->table()->bulk(), ['tags' => ['Sunset', 'Custom']])
            ->assertHasActionErrors(['tags']);

        expect(Tagging::namesOf($photo))->toBe([]);

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$photo->id])
            ->callAction(TestAction::make('addTags')->table()->bulk(), ['tags' => ['sunset']])
            ->assertHasNoActionErrors();

        expect(Tagging::namesOf($photo))->toBe(['Sunset']);
    });

    it('warns about photos that already have the most tags allowed and adds nothing there', function () {
        $album = Album::factory()->create();
        $full = galleryPhoto($album, name: 'Full.jpg');
        tagged($full, ...array_map(fn (int $number) => "Tag {$number}", range(1, Tagging::MAX_PER_ITEM)));
        $room = galleryPhoto($album, name: 'Room.jpg');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$full->id, $room->id])
            ->callAction(TestAction::make('addTags')->table()->bulk(), ['tags' => ['Extra']])
            ->assertNotified('Tags added to 2 photos');

        expect($full->tags()->count())->toBe(Tagging::MAX_PER_ITEM)
            ->and(Tagging::namesOf($room))->toBe(['Extra']);
    });

    it('offers the tag actions only while tagging is on', function () {
        Setting::put(SiteSettings::TAGGING, false);

        Livewire::test(ListPhotos::class)
            ->assertActionHidden(TestAction::make('addTags')->table()->bulk())
            ->assertActionHidden(TestAction::make('removeTags')->table()->bulk());
    });

    it('removes tags from the selected photos and deletes the tags nothing uses any more', function () {
        $album = Album::factory()->create();
        $first = tagged(galleryPhoto($album, name: 'First.jpg'), 'Gone', 'Shared', 'Stays');
        $second = tagged(galleryPhoto($album, name: 'Second.jpg'), 'Shared');
        $unselected = tagged(galleryPhoto($album, name: 'Unselected.jpg'), 'Shared');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('removeTags')->table()->bulk(), ['tags' => ['Gone', 'Shared']])
            ->assertNotified('Tags removed from 2 photos');

        expect(Tagging::namesOf($first))->toBe(['Stays'])
            ->and(Tagging::namesOf($second))->toBe([])
            ->and(Tagging::namesOf($unselected))->toBe(['Shared'])
            ->and(Tag::pluck('name')->sort()->values()->all())->toBe(['Shared', 'Stays']);
    });
});

describe('renaming', function () {
    beforeEach(fn () => $this->admin = actingAsRole(Role::Admin));

    it('titles the photos from the pattern, counting in the order they were taken', function () {
        $camp = Album::factory()->create(['title' => 'Camp']);
        $uploader = User::factory()->create(['name' => 'Ulla']);
        $later = galleryPhoto($camp, $uploader, 'Later.jpg');
        $later->update(['taken_at' => '2024-05-02 10:00:00']);
        $earlier = galleryPhoto($camp, $uploader, 'Earlier.jpg');
        $earlier->update(['taken_at' => '2024-05-01 10:00:00']);
        $unknown = galleryPhoto($camp, $uploader, 'Unknown.jpg');
        $storedName = $earlier->original()->file_name;

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$later->id, $unknown->id, $earlier->id])
            ->callAction(TestAction::make('rename')->table()->bulk(), ['pattern' => '{album} {uploader} {taken} {n} {original}', 'start' => 8, 'digits' => 3])
            ->assertHasNoActionErrors()
            ->assertNotified('3 photos renamed');

        expect($earlier->fresh()->title)->toBe('Camp Ulla 2024-05-01 008 earlier')
            ->and($later->fresh()->title)->toBe('Camp Ulla 2024-05-02 009 later')
            // Without a date taken, the upload date counts, and the photo comes last.
            ->and($unknown->fresh()->title)->toBe('Camp Ulla '.now()->format('Y-m-d').' 010 unknown')
            ->and($earlier->fresh()->original()->file_name)->toBe($storedName);
    });

    it('lets an empty placeholder leave no stray separators and names the unsorted album when that is all there is', function () {
        $album = Album::factory()->create(['title' => 'Camp']);
        $untitled = galleryPhoto($album, name: 'Untitled.jpg');
        $untitled->update(['title' => null]);
        $unsorted = Photo::factory()->inAlbum(Album::inbox($this->admin))->create(['uploader_id' => $this->admin->id]);

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$untitled->id])
            ->callAction(TestAction::make('rename')->table()->bulk(), ['pattern' => '{title} - {n}', 'start' => 1, 'digits' => 2]);
        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$unsorted->id])
            ->callAction(TestAction::make('rename')->table()->bulk(), ['pattern' => '{album}', 'start' => 1, 'digits' => 1]);

        expect($untitled->fresh()->title)->toBe('01')
            ->and($unsorted->fresh()->title)->toBe('Unsorted uploads');
    });

    it('refuses a pattern with a placeholder that does not exist', function () {
        $photo = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$photo->id])
            ->callAction(TestAction::make('rename')->table()->bulk(), ['pattern' => '{album} {nope}', 'start' => 1, 'digits' => 3])
            ->assertHasActionErrors(['pattern']);

        expect($photo->fresh()->title)->toBe('Lake');
    });

    it('logs the bulk rename as one entry', function () {
        $album = Album::factory()->create();
        $photos = collect(['A', 'B', 'C'])->map(fn (string $name) => galleryPhoto($album, null, "{$name}.jpg"));
        $before = (int) ActivityEvent::max('id');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords($photos->pluck('id')->all())
            ->callAction(TestAction::make('rename')->table()->bulk(), ['pattern' => 'Trip {n}', 'start' => 1, 'digits' => 1]);

        $event = ActivityEvent::where('id', '>', $before)->where('subject_type', Photo::class)->sole();
        expect($event->type)->toBe(ActivityType::Updated)
            ->and($event->properties['count'])->toBe(3);
    });

    it('leaves out the selected photos they may not edit', function () {
        $helper = uploadOnlyStaff();
        $this->actingAs($helper);
        $mine = galleryPhoto(Album::factory()->for($helper, 'owner')->create(), $helper, 'Mine.jpg');
        $foreign = galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Foreign.jpg');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$mine->id, $foreign->id])
            ->callAction(TestAction::make('rename')->table()->bulk(), ['pattern' => 'Trip {n}', 'start' => 1, 'digits' => 3])
            ->assertNotified('1 photo renamed');

        expect($mine->fresh()->title)->toBe('Trip 001')
            ->and($foreign->fresh()->title)->toBe('Foreign');
    });
});

describe('changing the uploader', function () {
    it('is offered only to people who may edit every album', function () {
        $this->actingAs(uploadOnlyStaff());

        Livewire::test(ListPhotos::class)->assertActionHidden(TestAction::make('changeUploader')->table()->bulk());

        actingAsRole(Role::Moderator);

        Livewire::test(ListPhotos::class)->assertActionVisible(TestAction::make('changeUploader')->table()->bulk());
    });

    it('hands the photos over and moves them from the previous uploader\'s unsorted uploads to the new one\'s', function () {
        actingAsRole(Role::Admin);
        $previous = userWithRole(Role::Uploader);
        $next = userWithRole(Role::Uploader);
        $unsorted = galleryPhoto(Album::inbox($previous), $previous, 'Unsorted.jpg');
        $inAlbum = galleryPhoto(Album::factory()->create(), $previous, 'Placed.jpg');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$unsorted->id, $inAlbum->id])
            ->callAction(TestAction::make('changeUploader')->table()->bulk(), ['uploader_id' => $next->id])
            ->assertNotified('2 photos now belong to '.$next->name);

        expect($unsorted->fresh()->uploader_id)->toBe($next->id)
            ->and($unsorted->albums()->sole()->is(Album::inbox($next)))->toBeTrue()
            ->and($inAlbum->fresh()->uploader_id)->toBe($next->id)
            ->and($inAlbum->albums()->sole()->system_album)->toBeNull()
            ->and(Album::inbox($previous)->photos()->count())->toBe(0);
    });

    it('refuses someone who may not upload photos', function () {
        actingAsRole(Role::Admin);
        $photo = galleryPhoto(Album::factory()->create(), null, 'Lake.jpg');
        $uploaderBefore = $photo->uploader_id;

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$photo->id])
            ->callAction(TestAction::make('changeUploader')->table()->bulk(), ['uploader_id' => userWithRole(Role::User)->id])
            ->assertStatus(403);

        expect($photo->fresh()->uploader_id)->toBe($uploaderBefore);
    });
});

describe('removing from all albums', function () {
    it('moves the photos to their uploader\'s unsorted uploads, passes covers on and logs each album once', function () {
        $admin = actingAsRole(Role::Admin);
        $uploader = userWithRole(Role::Uploader);
        $camp = Album::factory()->create(['title' => 'Camp']);
        $best = Album::factory()->create(['title' => 'Best of']);
        $selected = galleryPhoto($camp, $uploader, 'Selected.jpg');
        $best->addPhotos($selected);
        $orphan = Photo::factory()->inAlbum($camp)->create(['uploader_id' => null]);
        $staying = galleryPhoto($camp, $uploader, 'Staying.jpg');
        $camp->update(['cover_photo_id' => $selected->id]);
        $before = (int) ActivityEvent::max('id');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$selected->id, $orphan->id])
            ->callAction(TestAction::make('removeFromAllAlbums')->table()->bulk())
            ->assertNotified('2 photos removed from all albums');

        expect($selected->albums()->sole()->is(Album::inbox($uploader)))->toBeTrue()
            ->and($orphan->albums()->sole()->is(Album::inbox($admin)))->toBeTrue()
            ->and($camp->fresh()->cover_photo_id)->toBe($staying->id)
            ->and($camp->photos()->pluck('photos.id')->all())->toBe([$staying->id])
            ->and(ActivityEvent::where('id', '>', $before)->where('type', ActivityType::PhotosRemoved)->where('subject_type', Album::class)->pluck('subject_id')->sort()->values()->all())
            ->toBe([$camp->id, $best->id]);
    });

    it('refuses photos the person may not move to the trash', function () {
        $helper = uploadOnlyStaff();
        $this->actingAs($helper);
        $mine = galleryPhoto(Album::factory()->for($helper, 'owner')->create(), $helper, 'Mine.jpg');
        $publicAlbum = Album::factory()->visibility(Visibility::Public)->create();
        $foreign = galleryPhoto($publicAlbum, name: 'Foreign.jpg');
        $mineAlbum = $mine->albums()->sole();

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$mine->id, $foreign->id])
            ->callAction(TestAction::make('removeFromAllAlbums')->table()->bulk())
            ->assertNotified('1 photo removed from all albums');

        expect($mine->albums()->sole()->is(Album::inbox($helper)))->toBeTrue()
            ->and($mine->albums()->pluck('albums.id')->all())->not->toContain($mineAlbum->id)
            ->and($foreign->albums()->sole()->is($publicAlbum))->toBeTrue();
    });
});

describe('downloading a selection', function () {
    it('sends the downloadable selected photos to a one-time zip and leaves out the others', function () {
        $helper = uploadOnlyStaff();
        $this->actingAs($helper);
        $mine = galleryPhoto(Album::factory()->for($helper, 'owner')->create(), $helper, 'Mine.jpg');
        $foreign = galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Foreign.jpg');

        $page = Livewire::test(ListPhotos::class)
            ->selectTableRecords([$mine->id, $foreign->id])
            ->callAction(TestAction::make('download')->table()->bulk())
            ->assertNotified('1 photo was left out because you cannot download it.');

        $page->assertRedirectContains(url('/photo-downloads').'/');
        $key = basename($page->effects['redirect']);

        $response = $this->get(route('media.selection.download', $key));
        $response->assertOk();
        expect(zipEntries($response))->toBe(['Mine.jpg']);
        $this->get(route('media.selection.download', $key))->assertNotFound();
    });

    it('names the files after the photo titles and numbers repeated names', function () {
        $admin = actingAsRole(Role::Admin);
        $album = Album::factory()->create();
        $first = galleryPhoto($album, name: 'One.jpg');
        $second = galleryPhoto($album, name: 'Two.jpg');
        $third = galleryPhoto($album, name: 'Three.jpg');
        $untitled = galleryPhoto($album, name: 'Untitled.jpg');
        $first->update(['title' => 'Beach: day 1']);
        $second->update(['title' => 'beach: DAY 1']);
        $third->update(['title' => 'Beach: day 1']);
        $untitled->update(['title' => null]);
        $key = PhotoSelectionDownloadController::prepare([$first->id, $second->id, $third->id, $untitled->id]);

        $response = $this->get(route('media.selection.download', $key));

        $response->assertOk();
        expect(zipEntries($response))->toBe(['Beach- day 1 (2).jpg', 'Beach- day 1.jpg', 'beach- DAY 1 (1).jpg', $untitled->original()->file_name]);
    });

    it('checks the selection again when the zip is fetched and refuses unknown keys', function () {
        $helper = uploadOnlyStaff();
        $this->actingAs($helper);
        $foreign = galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Foreign.jpg');
        $key = PhotoSelectionDownloadController::prepare([$foreign->id]);

        $this->get(route('media.selection.download', $key))->assertNotFound();
        $this->get(route('media.selection.download', 'does-not-exist'))->assertNotFound();

        auth()->logout();
        $this->get(route('media.selection.download', $key))->assertRedirect(route('login'));
    });
});
