<?php

use App\Enums\ActivityType;
use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Actions\ActionIcons;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Photos\Pages\EditPhoto;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Photos\Pages\ListTrashedPhotos;
use App\Filament\Resources\Photos\Pages\ViewPhoto;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Tables\FilterBar;
use App\Filament\Tables\MediaGrid;
use App\Filament\Trash\TrashActions;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

describe('for an administrator', function () {
    beforeEach(fn () => $this->admin = actingAsRole(Role::Admin));

    it('shows the library as a grid of compact tiles in each photo\'s shape and remembers a switch to the list', function () {
        $album = Album::factory()->create();
        galleryPhoto($album, name: 'Wide.jpg', width: 300, height: 200);
        $portrait = galleryPhoto($album, name: 'Tall.jpg', width: 200, height: 300);

        Livewire::test(ListPhotos::class)
            ->assertSet('mediaLayout', ListPhotos::GRID)
            ->assertCanSeeTableRecords([$portrait])
            ->assertSeeHtml('og-pick is-compact')
            ->assertSeeHtml('og-r-30')
            ->assertSeeHtml('og-r-13')
            ->assertSeeHtml(route('media.show', [$portrait->original(), 'small']).' 200w')
            ->assertSeeHtml('loading="eager" fetchpriority="high"')
            ->assertDontSeeHtml('loading="lazy"');
        $this->get(PhotoResource::getUrl('index'))->assertOk()->assertSee('window.ogJustifiedRows', false);

        Livewire::test(ListPhotos::class)
            ->callAction(TestAction::make('listLayout')->table())
            ->assertSet('mediaLayout', ListPhotos::LIST)
            ->assertCanSeeTableRecords([$portrait]);
        Livewire::test(ListPhotos::class)->assertSet('mediaLayout', ListPhotos::LIST);

        expect(MediaGrid::ratioClass(new Photo(['width' => 8000, 'height' => 1000])))->toBe('og-r-'.MediaGrid::RATIO_MAX)
            ->and(MediaGrid::ratioClass(new Photo))->toBe('og-r-30');
    });

    it('links the albums and the uploader in the list layout and cuts long titles and names short', function () {
        $album = Album::factory()->create(['title' => 'Summer trip']);
        $uploader = User::factory()->create(['name' => str_repeat('Long name ', 8)]);
        $photo = galleryPhoto($album, $uploader, 'Linked.jpg', 300, 200);
        $photo->update(['title' => str_repeat('Very long title ', 6)]);

        Livewire::test(ListPhotos::class)
            ->call('setLayout', ListPhotos::LIST)
            ->assertCanSeeTableRecords([$photo])
            ->assertSeeHtml(AlbumResource::getUrl('edit', ['record' => $album]))
            ->assertSeeHtml(UserResource::getUrl('edit', ['record' => $uploader]))
            ->assertSee(Str::limit($photo->title, 40))
            ->assertSee(Str::limit($uploader->name, 40))
            // A photo can be in several albums, so each one is a badge; the single uploader is not.
            ->assertTableColumnExists('albums', fn ($column) => $column->isBadge())
            ->assertTableColumnExists('uploader.name', fn ($column) => ! $column->isBadge());
    });

    it('shows on the photo page where the photo is, who uploaded it and its camera data, and saves what is editable', function () {
        $parent = Album::factory()->create(['title' => 'Summer camp']);
        $album = Album::factory()->create(['title' => 'Day 3', 'parent_id' => $parent->id]);
        $uploader = User::factory()->create(['name' => 'Ulla Uploader']);
        $photo = galleryPhoto($album, $uploader, 'Hike.jpg', 300, 200);
        $photo->update(['exif' => ['Model' => 'X100V', 'FNumber' => '28/10', 'ExposureTime' => '1/250']]);
        $album->update(['cover_photo_id' => $photo->id]);

        $this->get(PhotoResource::getUrl('edit', ['record' => $photo]))
            ->assertOk()
            ->assertSee('Summer camp › Day 3')
            ->assertSee('Ulla Uploader')
            ->assertSee('hike.jpg')
            ->assertSee('X100V')
            ->assertSee('f/2.8')
            ->assertSee('1/250 s')
            ->assertSee(AlbumResource::getUrl('edit', ['record' => $album]), false)
            ->assertSee(UserResource::getUrl('edit', ['record' => $uploader]), false);

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->assertSee('Where it is')
            ->assertSee('History')
            ->set('data.title', 'Sunrise')
            ->set('data.taken_at', '2024-05-17 14:30:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        expect($photo->fresh())->title->toBe('Sunrise')
            ->and($photo->fresh()->taken_at->format('Y-m-d H:i'))->toBe('2024-05-17 14:30');
    });

    it('changes the license from the photo form (logged) and the bulk action, only while licensing is on', function () {
        $album = Album::factory()->create();
        $before = galleryPhoto($album, name: 'Beach.jpg', width: 300, height: 200);
        $other = galleryPhoto($album, name: 'Dunes.jpg', width: 200, height: 300);

        Livewire::test(EditPhoto::class, ['record' => $before->id])->assertFormFieldHidden('license_id');
        Livewire::test(ListPhotos::class)->assertActionHidden(TestAction::make('setLicense')->table()->bulk());

        $default = enableLicensing();
        $photo = galleryPhoto($album, name: 'Surf.jpg', width: 240, height: 160);
        expect($photo->license_id)->toBe($default->id);

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->assertFormFieldVisible('license_id')
            ->assertFormFieldEnabled('license_id')
            ->assertFormSet(['license_id' => $default->id])
            ->assertSeeHtml(e($default->displayDescription()))
            ->fillForm(['license_id' => packLicense('CC BY 4.0')->id])
            ->assertSeeHtml(e(packLicense('CC BY 4.0')->displayDescription()))
            ->assertSeeHtml('Read the license')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($photo->fresh()->license_id)->toBe(packLicense('CC BY 4.0')->id);
        $event = ActivityEvent::where('type', ActivityType::Updated)->where('subject_type', Photo::class)->where('subject_id', $photo->id)->sole();
        expect($event->details())->toContain('License: All rights reserved → CC BY 4.0');

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->fillForm(['license_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        expect($photo->fresh()->license_id)->toBeNull();

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$photo->id, $other->id])
            ->callAction(TestAction::make('setLicense')->table()->bulk(), ['license_id' => packLicense('CC0 1.0')->id])
            ->assertNotified('License set for 2 photos');

        expect($photo->fresh()->license_id)->toBe(packLicense('CC0 1.0')->id)
            ->and($other->fresh()->license_id)->toBe(packLicense('CC0 1.0')->id);
    });

    it('logs the bulk license action as one entry that counts only the photos whose license changed', function () {
        enableLicensing();
        $album = Album::factory()->create();
        $changed = collect([[300, 200], [200, 300], [240, 160]])->map(fn (array $size) => galleryPhoto($album, null, 'P'.$size[0].'.jpg', ...$size));
        $already = tap(galleryPhoto($album, null, 'Done.jpg', 120, 80))->update(['license_id' => packLicense('CC0 1.0')->id]);
        $before = (int) ActivityEvent::max('id');

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([...$changed->pluck('id')->all(), $already->id])
            ->callAction(TestAction::make('setLicense')->table()->bulk(), ['license_id' => packLicense('CC0 1.0')->id]);

        $event = ActivityEvent::where('id', '>', $before)->where('subject_type', Photo::class)->sole();
        expect($event->type)->toBe(ActivityType::Updated)
            ->and($event->properties['count'])->toBe(3)
            ->and(Photo::where('license_id', packLicense('CC0 1.0')->id)->count())->toBe(4);
    });

    it('adds and removes photos with bulk actions, keeping every photo in at least one album', function () {
        $camp = Album::factory()->create(['title' => 'Camp']);
        $best = Album::factory()->create(['title' => 'Best of']);
        [$a, $b] = [galleryPhoto($camp, name: 'A.jpg', width: 300, height: 200), galleryPhoto($camp, name: 'B.jpg', width: 300, height: 200)];
        $unsorted = Photo::factory()->inAlbum(Album::inbox($this->admin))->create(['uploader_id' => $this->admin->id]);
        $mine = Album::factory()->for($this->admin, 'owner')->create();

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$a->id])
            ->callAction(TestAction::make('addToAlbum')->table()->bulk(), ['album_id' => $best->id])
            ->assertNotified('1 photo added to Best of');

        expect($a->albums()->pluck('title')->sort()->values()->all())->toBe(['Best of', 'Camp'])
            ->and($best->fresh()->cover_photo_id)->toBe($a->id);

        Livewire::test(ListPhotos::class)
            ->call('setLayout', ListPhotos::LIST)
            ->assertActionHasIcon(TestAction::make('removeFromAlbum')->table()->bulk(), ActionIcons::REMOVE)
            ->assertActionHasColor(TestAction::make('removeFromAlbum')->table()->bulk(), 'gray')
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(TestAction::make('removeFromAlbum')->table()->bulk(), ['album_id' => $camp->id]);

        expect($a->albums()->pluck('title')->all())->toBe(['Best of'])
            ->and($b->albums()->pluck('title')->all())->toBe(['Camp'])
            ->and($camp->fresh()->cover_photo_id)->toBe($b->id);

        // Photos in "Unsorted uploads" leave it when they are added to an album.
        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$unsorted->id])
            ->callAction(TestAction::make('addToAlbum')->table()->bulk(), ['album_id' => $mine->id]);

        expect($unsorted->fresh()->albums->modelKeys())->toBe([$mine->id]);
    });

    it('filters the library like every admin list, by upload date too, and keeps the filters in the list layout', function () {
        $album = Album::factory()->create();
        $old = galleryPhoto($album, name: 'Old.jpg', width: 300, height: 200);
        $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        $new = galleryPhoto($album, name: 'New.jpg', width: 300, height: 200);

        $page = Livewire::test(ListPhotos::class);
        $table = $page->instance()->getTable();
        $filters = array_keys($table->getFilters());

        expect($table->getFiltersLayout())->toBe(FiltersLayout::Hidden)
            ->and(FilterBar::uses($table))->toBeTrue()
            ->and($table->hasDeferredFilters())->toBeFalse();

        $page->call('setLayout', ListPhotos::LIST);
        expect(array_keys($page->instance()->getTable()->getFilters()))->toBe($filters);

        $page->filterTable('uploaded', ['from' => now()->subWeek()->toDateString()])
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$old]);
    });

    it('shows the filters as chips above the library, with the choice on the chip and a way to reset all', function () {
        $album = Album::factory()->create(['title' => 'Summer trip']);
        galleryPhoto($album, name: 'Beach.jpg');

        $this->get(PhotoResource::getUrl('index'))
            ->assertOk()
            ->assertSee(['og-filter-chip', 'Album', 'Taken', 'Tags', 'Uploaded by', 'Shape', 'More filters'], false)
            ->assertDontSee('Reset all');

        $this->get(PhotoResource::getUrl('index', ['filters' => ['albums' => ['values' => [$album->id]], 'taken' => ['from' => '2024-01-01', 'until' => '2024-12-31']]]))
            ->assertOk()
            ->assertSee(['Summer trip', '01.01.2024 – 31.12.2024', 'Reset all']);
    });

    it('opens the library on the album of the dashboard\'s "Unsorted uploads" link', function () {
        $inbox = Album::inbox($this->admin);
        $waiting = Photo::factory()->inAlbum($inbox)->create(['uploader_id' => $this->admin->id]);
        $sorted = Photo::factory()->inAlbum(Album::factory()->create())->create();

        Livewire::withQueryParams(['filters' => ['albums' => ['values' => [$inbox->id]]]])
            ->test(ListPhotos::class)
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$sorted]);
    });

    it('moves photos to the trash, hides them from the album and passes the cover on', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        [$first, $second] = [galleryPhoto($album, name: 'One.jpg', width: 300, height: 200), galleryPhoto($album, name: 'Two.jpg', width: 300, height: 200)];
        $media = $first->original();

        Livewire::test(ListPhotos::class)->call('setLayout', ListPhotos::LIST)->callAction(TestAction::make('delete')->table($first));

        expect($first->fresh()->trashed())->toBeTrue()
            ->and($album->fresh()->cover_photo_id)->toBe($second->id);
        $this->get(route('albums.show', $album))->assertDontSee(route('media.show', [$media, 'thumb']));
        auth()->logout();
        $this->get(route('media.show', [$media, 'thumb']))->assertNotFound();
    });

    it('opens the trash at its own address and offers only restore or delete permanently there', function () {
        $this->get(PhotoResource::getUrl('trash'))->assertOk()->assertSee('The trash is empty');

        foreach (['restore', 'forceDelete'] as $action) {
            Livewire::test(ListPhotos::class)->assertActionDoesNotExist(TestAction::make($action)->table()->bulk());
        }
        Livewire::test(ListTrashedPhotos::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
            ->assertActionExists(TestAction::make('restore')->table()->bulk());
    });

    it('marks photos whose albums are all in the trash as not on the site', function () {
        $album = Album::factory()->create();
        Photo::factory()->inAlbum($album)->create();
        $album->delete();

        Livewire::test(ListPhotos::class)->assertSee('Not on the site');
    });

    it('restores photos and deletes them permanently including their files', function () {
        $photo = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg', width: 300, height: 200);
        $file = $photo->original()->getPathRelativeToRoot();
        $photo->delete();

        Livewire::test(ListTrashedPhotos::class)
            ->call('setLayout', ListPhotos::LIST)
            ->assertActionExists(TestAction::make('view')->table($photo))
            ->assertActionExists(TestAction::make('restore')->table($photo))
            ->assertActionExists(TestAction::make('forceDelete')->table($photo))
            ->callAction(TestAction::make('restore')->table($photo));
        expect($photo->fresh()->trashed())->toBeFalse();

        $photo->delete();
        Livewire::test(ListTrashedPhotos::class)
            ->call('setLayout', ListPhotos::LIST)
            ->callAction(TestAction::make('forceDelete')->table($photo));

        expect(Photo::withTrashed()->find($photo->id))->toBeNull();
        Storage::disk('gallery')->assertMissing($file);
    });

    it('restores a photo from the read-only page and sends an editor on to the edit page', function () {
        $photo = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg', width: 300, height: 200);
        $photo->delete();

        Livewire::test(ViewPhoto::class, ['record' => $photo->id])
            ->assertOk()
            ->callAction('restore')
            ->assertRedirect(PhotoResource::getUrl('edit', ['record' => $photo]));

        expect($photo->fresh()->trashed())->toBeFalse();
    });

    it('uses the photo page that fits the person for the activity link of a photo', function () {
        $photo = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg', width: 300, height: 200);
        $event = ActivityEvent::factory()->make();
        $event->setRelation('subject', $photo);

        expect($event->subjectUrl())->toBe(PhotoResource::getUrl('edit', ['record' => $photo]));
    });

    it('shows a photo in every album it is in, and to everyone who can see one of them', function () {
        $private = Album::factory()->create(['title' => 'Family only']);
        $public = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Best of']);
        $photo = galleryPhoto($private, name: 'Lake.jpg', width: 300, height: 200);
        $public->addPhotos($photo);
        $thumb = route('media.show', [$photo->original(), 'thumb']);
        $this->get(PhotoResource::getUrl('edit', ['record' => $photo]))->assertSee('Family only')->assertSee('Best of');

        auth()->logout();

        $this->get(route('albums.show', $public))->assertSee($thumb, false);
        $this->get($thumb)->assertOk();
    });
});

describe('for upload-only staff', function () {
    beforeEach(function () {
        $this->helper = uploadOnlyStaff();
        $this->actingAs($this->helper);
    });

    it('lists only photos from visible albums and own uploads', function () {
        $public = galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Lake.jpg', width: 300, height: 200);
        $own = galleryPhoto(Album::factory()->create(), $this->helper, 'Lake.jpg', 300, 200);
        $hidden = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg', width: 300, height: 200);

        Livewire::test(ListPhotos::class)
            ->assertCanSeeTableRecords([$public, $own])
            ->assertCanNotSeeTableRecords([$hidden]);
        $this->get(PhotoResource::getUrl('view', ['record' => $hidden]))->assertNotFound();
    });

    it('offers only albums they may list on the album chip, each with its photo count', function () {
        $own = Album::factory()->for($this->helper, 'owner')->create(['title' => 'Camp']);
        $public = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Open day']);
        $secret = Album::factory()->create(['title' => 'Secret plans']);
        $first = galleryPhoto($own, $this->helper, 'One.jpg');
        $second = galleryPhoto($own, $this->helper, 'Two.jpg');
        $secret->addPhotos($second);
        galleryPhoto($public, name: 'Three.jpg');
        galleryPhoto($secret, name: 'Four.jpg');

        $page = Livewire::test(ListPhotos::class)->assertCanSeeTableRecords([$first, $second]);
        $albums = collect(FilterBar::chips($page->instance()->getTable()))->firstWhere('name', 'albums');

        expect($albums['options'])->toBe([
            (string) $own->id => ['label' => 'Camp', 'count' => 2],
            (string) $public->id => ['label' => 'Open day', 'count' => 1],
        ])->and($albums['limited'])->toBeFalse();
        $page->filterTable('albums', [$own->id])->assertCanSeeTableRecords([$first, $second]);
    });

    it('edits a photo and its tags, and changes its albums only within their rights', function () {
        $from = Album::factory()->for($this->helper, 'owner')->create(['title' => 'Camp']);
        $to = Album::factory()->for($this->helper, 'owner')->create(['title' => 'Best of']);
        $foreign = Album::factory()->create(['title' => 'Not mine']);
        $photo = galleryPhoto($from, $this->helper, 'Lake.jpg', 300, 200);

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->set('data.albums', [$from->id, $foreign->id])
            ->call('save')
            ->assertHasErrors(['data.albums' => 'You cannot add photos to "Not mine".']);

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->set('data.title', 'Sunset at the lake')
            ->set('data.albums', [$to->id])
            ->set('data.tag_names', ['Sunset'])
            ->call('save')
            ->assertHasNoErrors();

        expect($photo->fresh()->title)->toBe('Sunset at the lake')
            ->and($photo->albums()->pluck('albums.id')->all())->toBe([$to->id])
            ->and($from->fresh()->cover_photo_id)->toBeNull()
            ->and($to->fresh()->cover_photo_id)->toBe($photo->id)
            ->and(Tagging::namesOf($photo))->toBe(['Sunset']);

        $event = ActivityEvent::where('type', ActivityType::Updated)->where('subject_type', Photo::class)->sole();
        expect($event->details())->toContain('Title: Lake → Sunset at the lake', 'Albums: +Best of, −Camp');
    });

    it('lets them pick only from the licenses they may choose and refuses any other id', function () {
        $default = enableLicensing(settings: [SiteSettings::LICENSES_CHOOSABLE => [packLicense('CC BY 4.0')->id]]);
        $photo = galleryPhoto(Album::factory()->for($this->helper, 'owner')->create(), $this->helper);
        $allowed = [$default->id, packLicense('CC BY 4.0')->id];

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->assertFormFieldEnabled('license_id')
            ->assertFormFieldExists('license_id', fn (Select $field): bool => array_keys($field->getOptions()) === $allowed)
            ->fillForm(['license_id' => packLicense('CC0 1.0')->id])
            ->call('save')
            ->assertHasFormErrors(['license_id']);

        expect($photo->fresh()->license_id)->toBe($default->id);

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->fillForm(['license_id' => packLicense('CC BY 4.0')->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($photo->fresh()->license_id)->toBe(packLicense('CC BY 4.0')->id);
    });

    it('shows the license read-only and offers no bulk action while choosing is off, and refuses a forced value', function () {
        $default = enableLicensing(settings: [SiteSettings::LICENSES_ALLOW_CHOICE => false]);
        $photo = galleryPhoto(Album::factory()->for($this->helper, 'owner')->create(), $this->helper);

        Livewire::test(EditPhoto::class, ['record' => $photo->id])
            ->assertFormFieldVisible('license_id')
            ->assertFormFieldDisabled('license_id')
            ->set('data.license_id', packLicense('CC0 1.0')->id)
            ->call('save')
            ->assertHasFormErrors(['license_id']);

        expect($photo->fresh()->license_id)->toBe($default->id);

        Livewire::test(ListPhotos::class)->assertActionHidden(TestAction::make('setLicense')->table()->bulk());
    });

    it('shows the license on the read-only photo page of a photo they may not edit, only while licensing is on', function () {
        $photo = galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Foreign.jpg');
        $photo->update(['license_id' => packLicense('CC BY 4.0')->id]);

        Livewire::test(ViewPhoto::class, ['record' => $photo->id])->assertDontSee('CC BY 4.0');

        enableLicensing();

        Livewire::test(ViewPhoto::class, ['record' => $photo->id])->assertSee('CC BY 4.0');
    });

    it('sets a license on the selected photos they may edit and leaves the others out', function () {
        enableLicensing();
        $mine = galleryPhoto(Album::factory()->for($this->helper, 'owner')->create(), $this->helper, 'Mine.jpg', 300, 200);
        $foreign = galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Foreign.jpg', width: 200, height: 300);
        $before = $foreign->license_id;

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$mine->id, $foreign->id])
            ->callAction(TestAction::make('setLicense')->table()->bulk(), ['license_id' => packLicense('CC BY 4.0')->id])
            ->assertNotified('License set for 1 photo');

        expect($mine->fresh()->license_id)->toBe(packLicense('CC BY 4.0')->id)
            ->and($foreign->fresh()->license_id)->toBe($before);
    });

    it('keeps trashed photos on the trash page, counted for the person', function () {
        $mine = Album::factory()->for($this->helper, 'owner')->create();
        $kept = galleryPhoto($mine, $this->helper, 'Kept.jpg', 300, 200);
        $trashed = tap(galleryPhoto($mine, $this->helper, 'Gone.jpg', 300, 200))->delete();
        tap(galleryPhoto(Album::factory()->create(), name: 'Hidden.jpg', width: 300, height: 200))->delete();

        Livewire::test(ListPhotos::class)
            ->assertCanSeeTableRecords([$kept])
            ->assertCanNotSeeTableRecords([$trashed])
            ->assertActionHasUrl('trash', PhotoResource::getUrl('trash'));
        expect(Livewire::test(ListPhotos::class)->instance()->getAction('trash')->getBadge())->toBe('1');

        Livewire::test(ListTrashedPhotos::class)
            ->assertCanSeeTableRecords([$trashed])
            ->assertCanNotSeeTableRecords([$kept]);
    });

    it('does not let them delete photos they may not manage or start an album from a selection', function () {
        $photo = galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Lake.jpg', width: 300, height: 200);

        Livewire::test(ListPhotos::class)
            ->call('setLayout', ListPhotos::LIST)
            ->assertActionHidden(TestAction::make('delete')->table($photo))
            ->assertActionHidden(TestAction::make('newAlbum')->table()->bulk());
        Livewire::test(EditPhoto::class, ['record' => $photo->id])->assertForbidden();
    });
});

describe('for an uploader', function () {
    beforeEach(fn () => $this->uploader = actingAsRole(Role::Uploader, ['name' => 'Ada']));

    it('asks where the photos go and uploads them into that album', function () {
        $camp = Album::factory()->for($this->uploader, 'owner')->create(['title' => 'Camp']);

        $page = Livewire::test(ListPhotos::class)
            ->mountAction('uploadPhotos')
            ->fillForm(['target' => "album:{$camp->id}"])
            ->callMountedAction()
            ->assertSet('mountedActions.0.name', 'uploadInto');
        $target = $page->instance()->getMountedAction()->getModalContent()->getData()['uploadTo'];
        $photoId = uploadPhotoInChunks($target, 'tent.jpg')->assertOk()->json('photo');

        expect($target)->toBe("album:{$camp->id}")
            ->and(Photo::find($photoId)->albums()->pluck('albums.id')->all())->toBe([$camp->id]);
    });

    it('uploads into "Unsorted uploads" unless an album is chosen', function () {
        $page = Livewire::test(ListPhotos::class)->callAction('uploadPhotos');
        $target = $page->instance()->getMountedAction()->getModalContent()->getData()['uploadTo'];
        $photoId = uploadPhotoInChunks($target, 'tent.jpg')->assertOk()->json('photo');

        expect($target)->toBe('inbox')
            ->and(Photo::find($photoId)->albums()->sole()->is(Album::inbox($this->uploader)))->toBeTrue();
    });

    it('opens "Upload photos" right away from the dashboard link', function () {
        Livewire::withQueryParams(['action' => 'uploadPhotos'])
            ->test(ListPhotos::class)
            ->call('mountDefaultActions')
            ->assertSet('mountedActions.0.name', 'uploadPhotos');
    });

    it('refuses albums they may not upload into', function () {
        $someoneElses = Album::factory()->create();

        Livewire::test(ListPhotos::class)
            ->callAction('uploadPhotos', data: ['target' => "album:{$someoneElses->id}"])
            ->assertHasActionErrors(['target' => 'in']);
    });

    it('creates a private album of their own from the selected photos and opens it', function () {
        $mine = Album::factory()->for($this->uploader, 'owner')->create();
        [$first, $second] = [galleryPhoto($mine, $this->uploader, 'First.jpg', 300, 200), galleryPhoto($mine, $this->uploader, 'Second.jpg', 300, 200)];

        $page = Livewire::test(ListPhotos::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('newAlbum')->table()->bulk(), data: ['title' => 'Best of'])
            ->assertHasNoFormErrors();

        $album = Album::where('title', 'Best of')->sole();
        $page->assertRedirect(AlbumResource::getUrl('edit', ['record' => $album]));
        expect($album->owner_id)->toBe($this->uploader->id)
            ->and($album->visibility)->toBe(Visibility::Private)
            ->and($album->photos()->pluck('photos.id')->sort()->values()->all())->toBe([$first->id, $second->id])
            ->and(ActivityEvent::where('type', ActivityType::PhotosAdded)->sole()->summary())->toBe('Ada added 2 photos to Best of');
    });

    it('shows the albums and the uploader as plain text where they may not open them', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer trip']);
        $photo = galleryPhoto($album, $this->uploader, 'Plain.jpg', 300, 200);
        $albumUrl = AlbumResource::getUrl('edit', ['record' => $album]);
        $uploaderUrl = UserResource::getUrl('edit', ['record' => $this->uploader]);

        Livewire::test(ListPhotos::class)
            ->call('setLayout', ListPhotos::LIST)
            ->assertCanSeeTableRecords([$photo])
            ->assertSee('Summer trip')
            ->assertDontSeeHtml($albumUrl)
            ->assertDontSeeHtml($uploaderUrl);

        $this->get(PhotoResource::getUrl('edit', ['record' => $photo]))
            ->assertOk()
            ->assertSee('Summer trip')
            ->assertDontSee($albumUrl, false)
            ->assertDontSee($uploaderUrl, false);
    });

    it('offers the original for download only to people who may download it', function () {
        $photo = galleryPhoto(Album::factory()->for($this->uploader, 'owner')->create(), name: 'Lake.jpg', width: 300, height: 200);
        $download = route('media.show', ['media' => $photo->original(), 'download' => 1]);

        $this->get(PhotoResource::getUrl('edit', ['record' => $photo]))->assertOk()->assertSee($download);

        $this->actingAs(userWithPermissions('viewer-staff', [Permission::AccessAdmin, Permission::ViewAnyAlbum]))
            ->get(PhotoResource::getUrl('view', ['record' => $photo]))
            ->assertOk()
            ->assertDontSee($download);
    });
});

describe('photos other people moved to the trash', function () {
    beforeEach(function () {
        $owner = User::factory()->create();
        $album = Album::factory()->for($owner, 'owner')->create(['visibility' => Visibility::Public]);
        $this->othersPhoto = Photo::factory()->inAlbum($album)->create(['title' => 'Trashed by its owner', 'uploader_id' => $owner->id]);
        $this->othersPhoto->delete();
        $this->viewer = userWithPermissions('viewer', [Permission::AccessAdmin]);
        $this->ownTrashed = tap(Photo::factory()->create(['title' => 'Mine', 'uploader_id' => $this->viewer->id]))->delete();
    });

    it('are not listed or counted in the trash of someone who may not restore them', function () {
        $this->actingAs($this->viewer);

        Livewire::test(ListTrashedPhotos::class)
            ->assertCanSeeTableRecords([$this->ownTrashed])
            ->assertCanNotSeeTableRecords([$this->othersPhoto]);
        expect(TrashActions::count(PhotoResource::class))->toBe(1);
        $this->get(PhotoResource::getUrl('view', ['record' => $this->othersPhoto]))->assertNotFound();
    });

    it('are listed for staff who may edit every album, like PhotoPolicy::delete()', function () {
        actingAsRole(Role::Admin);

        // A photo in no album can only be restored by its uploader, even by staff.
        Livewire::test(ListTrashedPhotos::class)
            ->assertCanSeeTableRecords([$this->othersPhoto])
            ->assertCanNotSeeTableRecords([$this->ownTrashed]);
    });
});

it('lists every photo for staff who may view any album', function () {
    $photos = [galleryPhoto(Album::factory()->create(), name: 'Lake.jpg', width: 300, height: 200), galleryPhoto(Album::factory()->visibility(Visibility::Public)->create(), name: 'Lake.jpg', width: 300, height: 200)];
    actingAsRole(Role::Moderator);

    Livewire::test(ListPhotos::class)->assertCanSeeTableRecords($photos);
});

it('offers uploading only to people who may upload photos', function () {
    $this->actingAs(userWithPermissions('viewer-staff', [Permission::AccessAdmin]));

    Livewire::test(ListPhotos::class)->assertActionHidden('uploadPhotos');
});

it('adds only own uploads to an album reached through a role, from the library and the photo form', function () {
    $user = addCollaborator($target = Album::factory()->create(), CollaboratorRole::Editor, userWithRole(Role::Uploader));
    $own = Album::factory()->for($user, 'owner')->create();
    $mine = Photo::factory()->inAlbum($own)->create(['uploader_id' => $user->id]);
    $notMine = Photo::factory()->inAlbum($own)->create(['uploader_id' => User::factory()]);
    $this->actingAs($user);

    Livewire::test(ListPhotos::class)
        ->selectTableRecords([$mine->id, $notMine->id])
        ->callAction(TestAction::make('addToAlbum')->table()->bulk(), ['album_id' => $target->id]);

    expect($target->photos()->pluck('photos.id')->all())->toBe([$mine->id]);

    $target->photos()->detach();

    Livewire::test(EditPhoto::class, ['record' => $notMine->getRouteKey()])
        ->fillForm(['albums' => [$own->id, $target->id]])
        ->call('save')
        ->assertHasFormErrors(['albums']);

    expect($target->photos()->count())->toBe(0);
});

it('sends each person to the photo page that fits them', function (string $viewer, string $opens, bool $trashed, string $lands) {
    $photo = galleryPhoto(Album::factory()->create(['title' => 'Family only']), name: 'Lake.jpg', width: 300, height: 200);
    $this->actingAs($viewer === 'editor'
        ? userWithRole(Role::Admin)
        : userWithPermissions('viewer-staff', [Permission::AccessAdmin, Permission::ViewAnyAlbum]));
    if ($trashed) {
        $photo->delete();
    }

    $response = $this->get(PhotoResource::getUrl($opens, ['record' => $photo]));

    $lands === 'stay'
        ? $response->assertOk()->assertSee('Family only')
        : $response->assertRedirect(PhotoResource::getUrl($lands, ['record' => $photo]));
    expect(PhotoResource::opensForEditing($photo))->toBe($lands === 'edit')
        ->and(PhotoResource::detailUrl($photo))->toBe(PhotoResource::getUrl($lands === 'edit' ? 'edit' : 'view', ['record' => $photo]));
})->with([
    'an editor opening the view URL' => ['editor', 'view', false, 'edit'],
    'an editor opening the edit URL of a trashed photo' => ['editor', 'edit', true, 'view'],
    'a person who may only view the photo' => ['viewer', 'view', false, 'stay'],
]);

it('links each list row to the photo page that fits the person and has only Move to trash as a record action', function (bool $mayEdit) {
    $owner = userWithRole($mayEdit ? Role::Uploader : Role::Admin);
    $photo = galleryPhoto(Album::factory()->for($owner, 'owner')->create(), $owner, 'Lake.jpg', 300, 200);
    $this->actingAs($mayEdit
        ? $owner
        : userWithPermissions('viewer-staff', [Permission::AccessAdmin, Permission::ViewAnyAlbum]));

    $list = Livewire::test(ListPhotos::class)->call('setLayout', ListPhotos::LIST);

    expect($list->instance()->getTable()->getRecordUrl($photo))->toBe(PhotoResource::getUrl($mayEdit ? 'edit' : 'view', ['record' => $photo]));
    $list->assertActionDoesNotExist(TestAction::make('view')->table($photo))
        ->assertActionDoesNotExist(TestAction::make('edit')->table($photo));
    $mayEdit
        ? $list->assertActionExists(TestAction::make('delete')->table($photo))
        : $list->assertActionHidden(TestAction::make('delete')->table($photo));
})->with(['someone who may edit' => true, 'someone who may only view' => false]);
