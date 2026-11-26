<?php

use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\Visibility;
use App\Livewire\MediaPicker;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('gallery');
    Storage::fake('local');
});

describe('for an administrator', function () {
    beforeEach(fn () => $this->admin = actingAsRole());

    it('hands back the chosen photos with their thumbnails, one photo in single mode without select-all', function () {
        [$a, $b] = [galleryPhoto(Album::factory()->create(), name: 'A.jpg', width: 200, height: 150), galleryPhoto(Album::factory()->create(), name: 'B.jpg', width: 200, height: 150)];

        Livewire::test(MediaPicker::class, ['key' => 'gallery-1'])
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(TestAction::make('choose')->table()->bulk())
            ->assertDispatched('media-picked', fn (string $event, array $params) => $params['key'] === 'gallery-1'
                && collect($params['items'])->pluck('id')->sort()->values()->all() === [$a->id, $b->id]
                && str_contains($params['items'][0]['thumb'], '/media/'));

        Livewire::test(MediaPicker::class, ['key' => 'cover', 'multiple' => false])
            ->assertActionDoesNotExist(TestAction::make('selectAll')->table())
            ->selectTableRecords([$a->id])
            ->callAction(TestAction::make('choose')->table()->bulk())
            ->assertDispatched('media-picked', key: 'cover', items: [['id' => $a->id, 'title' => 'A', 'thumb' => route('media.show', [$a->original(), 'thumb']), 'ratio' => $a->aspectRatio()]]);
    });

    it('narrows the pool to an album or leaves its photos out', function () {
        $album = Album::factory()->create();
        $in = galleryPhoto($album, width: 200, height: 150);
        $out = galleryPhoto(Album::factory()->create(), width: 200, height: 150);

        Livewire::test(MediaPicker::class, ['key' => 'k', 'scopeAlbumId' => $album->id, 'scope' => 'only'])
            ->assertCanSeeTableRecords([$in])->assertCanNotSeeTableRecords([$out]);
        Livewire::test(MediaPicker::class, ['key' => 'k', 'scopeAlbumId' => $album->id, 'scope' => 'exclude'])
            ->assertCanSeeTableRecords([$out])->assertCanNotSeeTableRecords([$in]);
    });

    it('filters like every admin list, by an upload date range too', function () {
        $album = Album::factory()->create();
        $old = galleryPhoto($album, name: 'Old.jpg', width: 200, height: 150);
        $old->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();
        $new = galleryPhoto($album, name: 'New.jpg', width: 200, height: 150);

        $picker = Livewire::test(MediaPicker::class, ['key' => 'k']);
        $table = $picker->instance()->getTable();

        expect($table->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
            ->and($table->hasDeferredFilters())->toBeFalse();

        $picker->filterTable('uploaded', ['from' => now()->subMonths(3)->toDateString(), 'until' => now()->subMonth()->toDateString()])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$new]);
    });

    it('uploads dropped photos into the configured place and selects them', function () {
        $album = Album::factory()->create();

        $picker = Livewire::test(MediaPicker::class, ['key' => 'cover', 'multiple' => false, 'scopeAlbumId' => $album->id, 'scope' => 'only', 'uploadTo' => 'page-images'])
            ->assertSeeHtml('ogPhotoUploader');
        expect(Album::where('system_album', Album::PAGE_IMAGES)->exists())->toBeFalse(); // not created just by opening

        $photoId = uploadPhotoInChunks('page-images', 'new.jpg')->assertOk()->json('photo');
        $picker->call('addUploaded', [$photoId])
            ->assertReturned([$photoId]);

        $pageImages = Album::where('system_album', Album::PAGE_IMAGES)->sole();
        $photo = $pageImages->photos()->sole();
        expect($photo->id)->toBe($photoId)
            ->and($pageImages->visibility->value)->toBe('private')
            ->and($picker->get('justUploaded'))->toBe([$photo->id]);

        // Shown although it is not in the scoped album.
        $picker->call('showUploaded')->assertCanSeeTableRecords([$photo]);
    });

    it('keeps the selection and filters while uploads arrive in batches, and redraws the grid only once they are done', function () {
        $earlier = galleryPhoto(Album::factory()->create(), $this->admin, width: 200, height: 150);

        $picker = Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox'])
            ->filterTable('uploader', $this->admin->id);

        $first = uploadPhotoInChunks('inbox', 'lake-view.jpg')->assertOk()->json('photo');
        $second = uploadPhotoInChunks('inbox', 'two.jpg')->assertOk()->json('photo');

        // Each batch returns only its own photos; the browser adds them to what is selected.
        $picker->call('addUploaded', [$first])->assertReturned([$first])
            ->assertDontSee('lake-view')
            ->call('addUploaded', [$second])->assertReturned([$second])
            ->assertNotDispatched('deselectAllTableRecords')
            ->assertSet('tableFilters.uploader.value', $this->admin->id)
            ->assertSet('justUploaded', [$first, $second])
            ->call('showUploaded')
            ->assertSee('lake-view')
            ->assertNotDispatched('deselectAllTableRecords')
            ->assertCanSeeTableRecords([$earlier, Photo::find($first), Photo::find($second)]);
    });

    it('only selects uploaded photos that are the person\'s own', function () {
        $someoneElses = galleryPhoto(Album::factory()->create(), User::factory()->create(), width: 200, height: 150);

        Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox'])
            ->call('addUploaded', [$someoneElses->id, 'x', 999999])
            ->assertSet('justUploaded', []);
    });

    it('offers no drop zone without an upload target, and keeps its settings out of reach of the browser', function () {
        Livewire::test(MediaPicker::class, ['key' => 'k'])->assertDontSeeHtml('ogPhotoUploader');

        Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox'])
            ->set('uploadTo', 'album:999');
    })->throws(CannotUpdateLockedPropertyException::class);

    it('escapes the opener\'s handler so it survives inside the HTML attribute', function () {
        $html = view('filament.media-picker.modal', [
            'key' => 'album-photos-1', 'multiple' => true, 'confirmLabel' => 'Add to album',
            'scopeAlbumId' => null, 'scope' => null, 'uploadTo' => null,
            'onPicked' => '$wire.call("addPickedPhotos", ids)',
        ])->render();

        expect($html)->toContain('$wire.call(&quot;addPickedPhotos&quot;, ids)')
            ->not->toContain('$wire.call("addPickedPhotos", ids)');
    });
});

describe('for people who may not use it fully', function () {
    it('only opens for staff and offers the drop zone only where the user may upload', function () {
        $this->actingAs(User::factory()->create());
        Livewire::test(MediaPicker::class, ['key' => 'k'])->assertForbidden();

        $this->actingAs(userWithPermissions('viewer-staff', [Permission::AccessAdmin]));
        Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox'])
            ->assertDontSeeHtml('ogPhotoUploader');
    });

    it('keeps the picker of a collaborator to this album and their own uploads', function () {
        $album = Album::factory()->create();
        $member = addCollaborator($album, CollaboratorRole::Editor);
        $inAlbum = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);
        $own = Photo::factory()->inAlbum(Album::factory()->create())->create(['uploader_id' => $member->id]);
        $public = Album::factory()->visibility(Visibility::Public)->create();
        $elsewhere = Photo::factory()->inAlbum($public)->create(['uploader_id' => $public->owner_id]);
        $this->actingAs($member);

        $picker = Livewire::test(MediaPicker::class, ['key' => 'cover', 'limitToAlbumId' => $album->id])
            ->assertCanSeeTableRecords([$inAlbum, $own])
            ->assertCanNotSeeTableRecords([$elsewhere]);

        expect($picker->instance()->getTable()->getFilter('uploader'))->toBeNull()
            ->and($picker->instance()->getTable()->getFilter('albums'))->toBeNull();
    });

    it('refuses the picker without admin access or a role on the album', function () {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        Livewire::test(MediaPicker::class, ['key' => 'x'])->assertForbidden();
        Livewire::test(MediaPicker::class, ['key' => 'x', 'limitToAlbumId' => $album->id])->assertForbidden();
    });
});
