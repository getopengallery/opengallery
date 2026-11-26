<?php

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Livewire\MediaPicker;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Role as RoleModel;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

describe('adding photos from the library', function () {
    it('adds photos chosen in the media picker to the album', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create(['title' => 'Best of']);
        $inAlbum = galleryPhoto($album);
        $elsewhere = galleryPhoto(Album::factory()->create());

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->call('addPickedPhotos', [$elsewhere->id, $inAlbum->id])
            ->assertNotified('1 photo added to Best of');

        expect($album->photos()->pluck('photos.id')->sort()->values()->all())->toBe([$inAlbum->id, $elsewhere->id])
            ->and(ActivityEvent::where('type', ActivityType::PhotosAdded)->sole()->summary())->toBe('Ada added 1 photo to Best of');
    });

    it('ignores photos the user may not see and albums they cannot add to', function () {
        RoleModel::findOrCreate('helper')->syncPermissions([Permission::AccessAdmin->value, Permission::UploadPhotos->value]);
        $helper = tap(User::factory()->create())->assignRole('helper');
        $own = Album::factory()->for($helper, 'owner')->create();
        $hidden = galleryPhoto(Album::factory()->create());
        $this->actingAs($helper);

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $own, 'pageClass' => EditAlbum::class])
            ->call('addPickedPhotos', [$hidden->id]);
        expect($own->photos()->count())->toBe(0);

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => Album::factory()->create(), 'pageClass' => EditAlbum::class])
            ->call('addPickedPhotos', [$hidden->id])
            ->assertForbidden();
    });
});

describe('album photos section', function () {
    it('shows the album photos with the cover marked on the edit page', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create();
        galleryPhoto($album);

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))
            ->assertOk()
            ->assertSee('Add photos')
            ->assertDontSee('Upload photos')
            ->assertSee('Cover');
    });

    it('removes photos from the album but keeps photos that are in no other album', function () {
        $admin = actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create(['title' => 'Camp']);
        $shared = galleryPhoto($album, name: 'Shared.jpg');
        Album::factory()->create()->addPhotos($shared);
        $only = galleryPhoto($album, name: 'Only.jpg');

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->selectTableRecords([$shared->id, $only->id])
            ->callAction(TestAction::make('removeFromAlbum')->table()->bulk())
            ->assertNotified('1 photo removed from Camp');

        expect($album->photos()->pluck('photos.id')->all())->toBe([$only->id])
            ->and(ActivityEvent::where('type', ActivityType::PhotosRemoved)->sole()->summary())->toBe("{$admin->name} removed 1 photo from Camp");
    });

    it('uses exactly one selected photo as the cover', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create();
        [$first, $second] = [galleryPhoto($album), galleryPhoto($album)];

        $manager = Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class]);
        $manager->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('useAsCover')->table()->bulk())
            ->assertNotified('Select exactly one photo to use as the cover.');
        expect($album->fresh()->cover_photo_id)->toBe($first->id);

        $manager->selectTableRecords([$second->id])->callAction(TestAction::make('useAsCover')->table()->bulk());
        expect($album->fresh()->cover_photo_id)->toBe($second->id);
    });

    it('opens "Add photos" on load when the site\'s Add photos button sends people here', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create();
        parse_str(parse_url(PhotosRelationManager::addPhotosUrl($album), PHP_URL_QUERY), $query);

        Livewire::withQueryParams($query)
            ->test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->call('mountDefaultActions')
            ->assertSet('mountedActions.0.name', 'addFromLibrary');
    });

    it('uploads photos in the album\'s picker straight into the album', function () {
        $admin = actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create(['title' => 'Camp']);
        $manager = Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->mountAction(TestAction::make('addFromLibrary')->table());
        $target = $manager->instance()->getMountedAction()->getModalContent()->getData()['uploadTo'];

        $photoId = uploadPhotoInChunks($target, 'lake.jpg')->assertOk()->json('photo');
        Livewire::test(MediaPicker::class, ['key' => 'album-photos', 'uploadTo' => $target])
            ->call('addUploaded', [$photoId])
            ->assertNotDispatched('album-photos-changed');
        $manager->call('addPickedPhotos', [$photoId])
            ->assertNotified('1 photo is in Camp');

        expect($target)->toBe("album:{$album->id}")
            ->and(Photo::find($photoId)->albums()->pluck('albums.id')->all())->toBe([$album->id])
            ->and(Album::inbox($admin)->photos()->count())->toBe(0)
            ->and(ActivityEvent::where('type', ActivityType::PhotosAdded)->exists())->toBeFalse();
    });

    it('adds photos uploaded in the picker to the album, out of "Unsorted uploads"', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create();

        $photoId = uploadPhotoInChunks('inbox', 'lake.jpg')->assertOk()->json('photo');
        $uploaded = Livewire::test(MediaPicker::class, ['key' => 'album-photos', 'uploadTo' => 'inbox'])
            ->call('addUploaded', [$photoId])
            ->get('justUploaded');

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->call('addPickedPhotos', $uploaded);

        expect($album->photos()->pluck('photos.id')->all())->toBe($uploaded)
            ->and(Photo::find($uploaded[0])->albums()->pluck('slug')->all())->toBe([$album->slug]);
    });
});

it('creates an album with the photos and cover chosen in the media picker', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada']);
    [$first, $second] = [galleryPhoto(Album::factory()->create()), galleryPhoto(Album::factory()->create())];
    $uploaded = galleryPhoto(Album::inbox($admin), name: 'Fresh.jpg');
    $cover = galleryPhoto(Album::factory()->create(), name: 'Cover.jpg');

    Livewire::test(CreateAlbum::class)
        ->set('data.title', 'Best of 2026')
        ->set('data.owner_id', $admin->id)
        ->set('data.photo_ids', [$second->id, $first->id, $uploaded->id])
        ->set('data.cover_photo_id', $cover->id)
        ->call('create')
        ->assertHasNoErrors();

    $album = Album::where('title', 'Best of 2026')->sole();
    expect($album->photos()->pluck('photos.id')->all())->toBe([$second->id, $first->id, $uploaded->id, $cover->id])
        ->and($album->cover_photo_id)->toBe($cover->id)
        ->and($uploaded->albums()->pluck('slug')->all())->toBe([$album->slug]); // left "Unsorted uploads"
});
