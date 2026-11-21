<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Photos\Pages\EditPhoto;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Photos\Pages\ListTrashedPhotos;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Tables\MediaGrid;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\Role as RoleModel;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

/** A real photo (with media files) in $album, uploaded by $uploader. */
function photoIn(Album $album, ?User $uploader = null, string $name = 'Lake.jpg', int $width = 300, int $height = 200): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    imagejpeg(imagecreatetruecolor($width, $height), $path);

    return app(AddPhotoToAlbum::class)->handle($album, $path, $name, $uploader);
}

/** Staff with admin access but without "view any album": sees only what their albums allow. */
function helper(): User
{
    RoleModel::findOrCreate('helper')->syncPermissions([Permission::AccessAdmin->value, Permission::UploadPhotos->value]);

    return tap(User::factory()->create())->assignRole('helper');
}

it('lists every photo for staff who may view any album', function () {
    $photos = [photoIn(Album::factory()->create()), photoIn(Album::factory()->visibility(Visibility::Public)->create())];

    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value));

    Livewire::test(ListPhotos::class)->assertCanSeeTableRecords($photos);
});

it('lists only photos from visible albums and own uploads for other staff', function () {
    $helper = helper();
    $public = photoIn(Album::factory()->visibility(Visibility::Public)->create());
    $own = photoIn(Album::factory()->create(), $helper);
    $hidden = photoIn(Album::factory()->create());

    $this->actingAs($helper);

    Livewire::test(ListPhotos::class)
        ->assertCanSeeTableRecords([$public, $own])
        ->assertCanNotSeeTableRecords([$hidden]);
    $this->get(PhotoResource::getUrl('view', ['record' => $hidden]))->assertNotFound();
});

it('counts a photo as visible to someone through any album they may list, even one in the trash', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $group->users()->attach($user);
    $sharedWithGroup = Album::factory()->visibility(Visibility::Restricted)->create();
    $sharedWithGroup->sharedGroups()->attach($group);
    $trashedPublic = Album::factory()->visibility(Visibility::Public)->create();
    $photos = [
        'public' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Public)->create())->create(),
        'members' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Registered)->create())->create(),
        'group share' => Photo::factory()->inAlbum($sharedWithGroup)->create(),
        'own private album' => Photo::factory()->inAlbum(Album::factory()->create(['owner_id' => $user->id]))->create(),
        'own upload elsewhere' => Photo::factory()->inAlbum(Album::factory()->create())->create(['uploader_id' => $user->id]),
        'public album in the trash' => Photo::factory()->inAlbum($trashedPublic)->create(),
        'not shared' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Restricted)->create())->create(),
        'unlisted' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Unlisted)->create())->create(),
        'expired' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Public)->create(['access_expires_at' => now()->subDay()]))->create(),
        'private' => Photo::factory()->inAlbum(Album::factory()->create())->create(),
    ];
    $trashedPublic->delete();

    $visible = Photo::query()->visibleTo($user)->pluck('id')->all();

    $expected = ['public', 'members', 'group share', 'own private album', 'own upload elsewhere', 'public album in the trash'];
    expect(collect($photos)->filter(fn (Photo $photo) => in_array($photo->id, $visible, true))->keys()->all())->toBe($expected);
});

it('shows where a photo is, who uploaded it and its camera data', function () {
    $admin = tap(User::factory()->create(['name' => 'Ada Admin']))->assignRole(Role::Admin->value);
    $parent = Album::factory()->create(['title' => 'Summer camp']);
    $album = Album::factory()->create(['title' => 'Day 3', 'parent_id' => $parent->id]);
    $photo = photoIn($album, $admin, 'Hike.jpg');
    $photo->update(['exif' => ['Model' => 'X100V', 'FNumber' => '28/10', 'ExposureTime' => '1/250']]);

    $this->actingAs($admin)
        ->get(PhotoResource::getUrl('view', ['record' => $photo]))
        ->assertOk()
        ->assertSee('Summer camp › Day 3')
        ->assertSee('Ada Admin')
        ->assertSee('hike.jpg')
        ->assertSee('X100V')
        ->assertSee('f/2.8')
        ->assertSee('1/250 s');
});

it('edits a photo and changes its albums only within the user\'s rights', function () {
    $helper = helper();
    $from = Album::factory()->for($helper, 'owner')->create(['title' => 'Camp']);
    $to = Album::factory()->for($helper, 'owner')->create(['title' => 'Best of']);
    $foreign = Album::factory()->create(['title' => 'Not mine']);
    $photo = photoIn($from, $helper);
    $this->actingAs($helper);

    Livewire::test(EditPhoto::class, ['record' => $photo->id])
        ->set('data.albums', [$from->id, $foreign->id])
        ->call('save')
        ->assertHasErrors(['data.albums' => 'You cannot add photos to "Not mine".']);

    Livewire::test(EditPhoto::class, ['record' => $photo->id])
        ->set('data.title', 'Sunset at the lake')
        ->set('data.albums', [$to->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($photo->fresh()->title)->toBe('Sunset at the lake')
        ->and($photo->albums()->pluck('albums.id')->all())->toBe([$to->id])
        ->and($from->fresh()->cover_photo_id)->toBeNull()
        ->and($to->fresh()->cover_photo_id)->toBe($photo->id);

    $event = ActivityEvent::where('type', ActivityType::Updated)->where('subject_type', Photo::class)->sole();
    expect($event->details())->toContain('Title: Lake → Sunset at the lake', 'Albums: +Best of, −Camp');
});

describe('photos in several albums', function () {
    it('shows a photo in every album it is in, and to everyone who can see one of them', function () {
        $private = Album::factory()->create(['title' => 'Family only']);
        $public = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Best of']);
        $photo = photoIn($private);
        $public->addPhotos($photo);
        $thumb = route('media.show', [$photo->original(), 'thumb']);

        $this->get(route('albums.show', $public))->assertSee($thumb, false);
        $this->get($thumb)->assertOk();

        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value))
            ->get(PhotoResource::getUrl('view', ['record' => $photo]))
            ->assertSee('Family only')
            ->assertSee('Best of');
    });

    it('adds and removes photos with bulk actions, keeping every photo in at least one album', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
        $camp = Album::factory()->create(['title' => 'Camp']);
        $best = Album::factory()->create(['title' => 'Best of']);
        [$a, $b] = [photoIn($camp, name: 'A.jpg'), photoIn($camp, name: 'B.jpg')];

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$a->id])
            ->callAction(TestAction::make('addToAlbum')->table()->bulk(), ['album_id' => $best->id])
            ->assertNotified('1 photo added to Best of');

        expect($a->albums()->pluck('title')->sort()->values()->all())->toBe(['Best of', 'Camp'])
            ->and($best->fresh()->cover_photo_id)->toBe($a->id);

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(TestAction::make('removeFromAlbum')->table()->bulk(), ['album_id' => $camp->id]);

        expect($a->albums()->pluck('title')->all())->toBe(['Best of'])
            ->and($b->albums()->pluck('title')->all())->toBe(['Camp'])
            ->and($camp->fresh()->cover_photo_id)->toBe($b->id);
    });

    it('lets only people who manage all of a photo\'s albums move it to the trash', function () {
        $helper = helper();
        $mine = Album::factory()->for($helper, 'owner')->create();
        $photo = photoIn(Album::factory()->create());
        $mine->addPhotos($photo);

        expect($helper->can('update', $photo))->toBeTrue()
            ->and($helper->can('delete', $photo))->toBeFalse();
    });

    it('moves existing photos into the album they were in when upgrading', function () {
        $migration = require database_path('migrations/2026_11_14_000000_create_album_photo_table.php');
        $album = Album::factory()->create();
        $photo = photoIn($album);

        $migration->down();
        expect(DB::table('photos')->where('id', $photo->id)->value('album_id'))->toBe($album->id);

        $migration->up();
        expect($photo->albums()->pluck('albums.id')->all())->toBe([$album->id]);
    });
});

it('shows the media library as a grid of compact tiles and remembers a switch to the list', function () {
    $photo = photoIn(Album::factory()->create(), name: 'Grid.jpg');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    Livewire::test(ListPhotos::class)
        ->assertSet('mediaLayout', ListPhotos::GRID)
        ->assertCanSeeTableRecords([$photo])
        ->assertSeeHtml('og-pick is-compact')
        ->callAction('listLayout')
        ->assertSet('mediaLayout', ListPhotos::LIST)
        ->assertCanSeeTableRecords([$photo]);

    Livewire::test(ListPhotos::class)->assertSet('mediaLayout', ListPhotos::LIST);
});

it('lays grid tiles out in each photo\'s shape, loading the first at once and offering the small size', function () {
    $album = Album::factory()->create();
    photoIn($album, name: 'Wide.jpg');
    $portrait = photoIn($album, name: 'Tall.jpg', width: 200, height: 300);
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    Livewire::test(ListPhotos::class)
        ->assertSeeHtml('og-r-30')
        ->assertSeeHtml('og-r-13')
        ->assertSeeHtml(route('media.show', [$portrait->original(), 'small']).' 200w')
        ->assertSeeHtml('loading="eager" fetchpriority="high"')
        ->assertDontSeeHtml('loading="lazy"');

    expect(MediaGrid::ratioClass(new Photo(['width' => 8000, 'height' => 1000])))->toBe('og-r-'.MediaGrid::RATIO_MAX)
        ->and(MediaGrid::ratioClass(new Photo))->toBe('og-r-30');
});

it('loads the script that completes the rows of the photo grids on admin pages', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    $this->get(PhotoResource::getUrl('index'))
        ->assertOk()
        ->assertSee('window.ogJustifiedRows', false);
});

it('moves photos to the trash, hides them from the album and passes the cover on', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    [$first, $second] = [photoIn($album, name: 'One.jpg'), photoIn($album, name: 'Two.jpg')];
    $media = $first->original();
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    Livewire::test(ListPhotos::class)->call('setLayout', ListPhotos::LIST)->callAction(TestAction::make('delete')->table($first));

    expect($first->fresh()->trashed())->toBeTrue()
        ->and($album->fresh()->cover_photo_id)->toBe($second->id);
    $this->get(route('albums.show', $album))->assertDontSee(route('media.show', [$media, 'thumb']));
    auth()->logout();
    $this->get(route('media.show', [$media, 'thumb']))->assertNotFound();
});

it('keeps trashed photos on the trash page, counted for the person', function () {
    $this->actingAs(helper());
    $mine = Album::factory()->for(auth()->user(), 'owner')->create();
    $kept = photoIn($mine, auth()->user(), 'Kept.jpg');
    $trashed = tap(photoIn($mine, auth()->user(), 'Gone.jpg'))->delete();
    tap(photoIn(Album::factory()->create(), name: 'Hidden.jpg'))->delete();

    Livewire::test(ListPhotos::class)
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$trashed])
        ->assertActionHasUrl('trash', PhotoResource::getUrl('trash'));
    expect(Livewire::test(ListPhotos::class)->instance()->getAction('trash')->getBadge())->toBe('1');

    Livewire::test(ListTrashedPhotos::class)
        ->assertCanSeeTableRecords([$trashed])
        ->assertCanNotSeeTableRecords([$kept]);
});

it('restores photos and deletes them permanently including their files', function () {
    $album = Album::factory()->create();
    $photo = photoIn($album);
    $file = $photo->original()->getPathRelativeToRoot();
    $photo->delete();
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    Livewire::test(ListTrashedPhotos::class)
        ->call('setLayout', ListPhotos::LIST)
        ->callAction(TestAction::make('restore')->table($photo));
    expect($photo->fresh()->trashed())->toBeFalse();

    $photo->delete();
    Livewire::test(ListTrashedPhotos::class)
        ->call('setLayout', ListPhotos::LIST)
        ->callAction(TestAction::make('forceDelete')->table($photo));

    expect(Photo::withTrashed()->find($photo->id))->toBeNull();
    Storage::disk('gallery')->assertMissing($file);
});

it('does not let staff delete photos they may not manage', function () {
    $helper = helper();
    $photo = photoIn(Album::factory()->visibility(Visibility::Public)->create());

    $this->actingAs($helper);

    Livewire::test(ListPhotos::class)->call('setLayout', ListPhotos::LIST)->assertActionHidden(TestAction::make('edit')->table($photo));
    Livewire::test(EditPhoto::class, ['record' => $photo->id])->assertForbidden();
});

it('filters the media library by upload date', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
    $album = Album::factory()->create();
    $old = photoIn($album, name: 'Old.jpg');
    $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();
    $new = photoIn($album, name: 'New.jpg');

    Livewire::test(ListPhotos::class)
        ->filterTable('uploaded', ['from' => now()->subWeek()->toDateString()])
        ->assertCanSeeTableRecords([$new])
        ->assertCanNotSeeTableRecords([$old]);
});

describe('uploading from the media library', function () {
    it('asks where the photos go and uploads them into that album', function () {
        $uploader = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $camp = Album::factory()->for($uploader, 'owner')->create(['title' => 'Camp']);
        $this->actingAs($uploader);

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
        $uploader = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $this->actingAs($uploader);

        $page = Livewire::test(ListPhotos::class)->callAction('uploadPhotos');
        $target = $page->instance()->getMountedAction()->getModalContent()->getData()['uploadTo'];
        $photoId = uploadPhotoInChunks($target, 'tent.jpg')->assertOk()->json('photo');

        expect($target)->toBe('inbox')
            ->and(Photo::find($photoId)->albums()->sole()->is(Album::inbox($uploader)))->toBeTrue();
    });

    it('opens "Upload photos" right away from the dashboard link', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Uploader->value));

        Livewire::withQueryParams(['action' => 'uploadPhotos'])
            ->test(ListPhotos::class)
            ->call('mountDefaultActions')
            ->assertSet('mountedActions.0.name', 'uploadPhotos');
    });

    it('refuses albums the person may not upload into', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Uploader->value));
        $someoneElses = Album::factory()->create();

        Livewire::test(ListPhotos::class)
            ->callAction('uploadPhotos', data: ['target' => "album:{$someoneElses->id}"])
            ->assertHasActionErrors(['target' => 'in']);
    });

    it('offers uploading only to people who may upload photos', function () {
        RoleModel::findOrCreate('viewer-staff')->syncPermissions([Permission::AccessAdmin->value]);
        $this->actingAs(tap(User::factory()->create())->assignRole('viewer-staff'));

        Livewire::test(ListPhotos::class)->assertActionHidden('uploadPhotos');
    });
});

it('creates a private album of the person\'s own from the selected photos and opens it', function () {
    $uploader = tap(User::factory()->create(['name' => 'Ada']))->assignRole(Role::Uploader->value);
    $mine = Album::factory()->for($uploader, 'owner')->create();
    [$first, $second] = [photoIn($mine, $uploader, 'First.jpg'), photoIn($mine, $uploader, 'Second.jpg')];
    $this->actingAs($uploader);

    $page = Livewire::test(ListPhotos::class)
        ->selectTableRecords([$first->id, $second->id])
        ->callAction(TestAction::make('newAlbum')->table()->bulk(), data: ['title' => 'Best of'])
        ->assertHasNoFormErrors();

    $album = Album::where('title', 'Best of')->sole();
    $page->assertRedirect(AlbumResource::getUrl('edit', ['record' => $album]));
    expect($album->owner_id)->toBe($uploader->id)
        ->and($album->visibility)->toBe(Visibility::Private)
        ->and($album->photos()->pluck('photos.id')->sort()->values()->all())->toBe([$first->id, $second->id])
        ->and(ActivityEvent::where('type', ActivityType::PhotosAdded)->sole()->summary())->toBe('Ada added 2 photos to Best of');
});

it('offers a new album from the selection only to people who may create albums', function () {
    $this->actingAs(helper());

    Livewire::test(ListPhotos::class)
        ->assertActionHidden(TestAction::make('newAlbum')->table()->bulk());
});

it('offers the original for download only to people who may download it', function () {
    RoleModel::findOrCreate('viewer-staff')->syncPermissions([Permission::AccessAdmin->value, Permission::ViewAnyAlbum->value]);
    $owner = tap(User::factory()->create())->assignRole(Role::Uploader->value);
    $photo = photoIn(Album::factory()->for($owner, 'owner')->create());
    $download = route('media.show', ['media' => $photo->original(), 'download' => 1]);

    $this->actingAs(tap(User::factory()->create())->assignRole('viewer-staff'))
        ->get(PhotoResource::getUrl('view', ['record' => $photo]))
        ->assertOk()
        ->assertDontSee($download);
    $this->actingAs($owner)
        ->get(PhotoResource::getUrl('view', ['record' => $photo]))
        ->assertOk()
        ->assertSee($download);
});
