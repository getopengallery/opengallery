<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\Permission;
use App\Enums\Role;
use App\Livewire\MediaPicker;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Role as RoleModel;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

function poolPhoto(Album $album, ?User $uploader = null, string $name = 'Photo.jpg'): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    imagejpeg(imagecreatetruecolor(200, 150), $path);

    return app(AddPhotoToAlbum::class)->handle($album, $path, $name, $uploader);
}

function pickerAdmin(): User
{
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    test()->actingAs($admin);

    return $admin;
}

it('hands back the chosen photos with their thumbnails, in several-photos mode', function () {
    pickerAdmin();
    [$a, $b] = [poolPhoto(Album::factory()->create(), name: 'A.jpg'), poolPhoto(Album::factory()->create(), name: 'B.jpg')];

    Livewire::test(MediaPicker::class, ['key' => 'gallery-1'])
        ->selectTableRecords([$a->id, $b->id])
        ->callAction(TestAction::make('choose')->table()->bulk())
        ->assertDispatched('media-picked', fn (string $event, array $params) => $params['key'] === 'gallery-1'
            && collect($params['items'])->pluck('id')->sort()->values()->all() === [$a->id, $b->id]
            && str_contains($params['items'][0]['thumb'], '/media/'));
});

it('hands back one photo in single mode and has no select-all', function () {
    pickerAdmin();
    $photo = poolPhoto(Album::factory()->create());

    Livewire::test(MediaPicker::class, ['key' => 'cover', 'multiple' => false])
        ->assertActionDoesNotExist(TestAction::make('selectAll')->table())
        ->selectTableRecords([$photo->id])
        ->callAction(TestAction::make('choose')->table()->bulk())
        ->assertDispatched('media-picked', key: 'cover', items: [['id' => $photo->id, 'title' => 'Photo', 'thumb' => route('media.show', [$photo->original(), 'thumb']), 'ratio' => $photo->aspectRatio()]]);
});

it('narrows the pool to an album or leaves its photos out', function () {
    pickerAdmin();
    $album = Album::factory()->create();
    $in = poolPhoto($album);
    $out = poolPhoto(Album::factory()->create());

    Livewire::test(MediaPicker::class, ['key' => 'k', 'scopeAlbumId' => $album->id, 'scope' => 'only'])
        ->assertCanSeeTableRecords([$in])->assertCanNotSeeTableRecords([$out]);
    Livewire::test(MediaPicker::class, ['key' => 'k', 'scopeAlbumId' => $album->id, 'scope' => 'exclude'])
        ->assertCanSeeTableRecords([$out])->assertCanNotSeeTableRecords([$in]);
});

it('uploads dropped photos into the configured place and selects them', function () {
    Storage::fake('local');
    pickerAdmin();
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

it('keeps the selection and filters while uploads arrive in batches', function () {
    Storage::fake('local');
    $admin = pickerAdmin();
    $earlier = poolPhoto(Album::factory()->create(), $admin);

    $picker = Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox'])
        ->filterTable('uploader', $admin->id);

    $first = uploadPhotoInChunks('inbox', 'one.jpg')->assertOk()->json('photo');
    $second = uploadPhotoInChunks('inbox', 'two.jpg')->assertOk()->json('photo');

    // Each batch returns only its own photos; the browser adds them to what is selected.
    $picker->call('addUploaded', [$first])->assertReturned([$first])
        ->call('addUploaded', [$second])->assertReturned([$second])
        ->assertNotDispatched('deselectAllTableRecords')
        ->assertSet('tableFilters.uploader.value', $admin->id)
        ->assertSet('justUploaded', [$first, $second])
        ->call('showUploaded')
        ->assertNotDispatched('deselectAllTableRecords')
        ->assertCanSeeTableRecords([$earlier, Photo::find($first), Photo::find($second)]);
});

it('redraws the grid only once the upload has finished', function () {
    Storage::fake('local');
    pickerAdmin();
    $picker = Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox']);
    $photoId = uploadPhotoInChunks('inbox', 'lake-view.jpg')->assertOk()->json('photo');

    $picker->call('addUploaded', [$photoId])
        ->assertReturned([$photoId])
        ->assertDontSee('lake-view');

    $picker->call('showUploaded')
        ->assertSee('lake-view');
});

it('only selects uploaded photos that are the person\'s own', function () {
    pickerAdmin();
    $someoneElses = poolPhoto(Album::factory()->create(), User::factory()->create());

    Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox'])
        ->call('addUploaded', [$someoneElses->id, 'x', 999999])
        ->assertSet('justUploaded', []);
});

it('offers the drop zone only where the user may upload', function () {
    RoleModel::findOrCreate('viewer-staff')->syncPermissions([Permission::AccessAdmin->value]);
    $this->actingAs(tap(User::factory()->create())->assignRole('viewer-staff'));

    Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox'])
        ->assertDontSeeHtml('ogPhotoUploader');

    pickerAdmin();
    Livewire::test(MediaPicker::class, ['key' => 'k'])
        ->assertDontSeeHtml('ogPhotoUploader');
});

it('filters by an upload date range', function () {
    pickerAdmin();
    $album = Album::factory()->create();
    $old = poolPhoto($album, name: 'Old.jpg');
    $old->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();
    $new = poolPhoto($album, name: 'New.jpg');

    Livewire::test(MediaPicker::class, ['key' => 'k'])
        ->filterTable('uploaded', ['from' => now()->subMonths(3)->toDateString(), 'until' => now()->subMonth()->toDateString()])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$new]);
});

it('only opens for staff and keeps its settings out of reach of the browser', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test(MediaPicker::class, ['key' => 'k'])->assertForbidden();

    pickerAdmin();
    Livewire::test(MediaPicker::class, ['key' => 'k', 'uploadTo' => 'inbox'])
        ->set('uploadTo', 'album:999');
})->throws(CannotUpdateLockedPropertyException::class);

it('escapes the opener\'s handler so it survives inside the HTML attribute', function () {
    pickerAdmin();

    $html = view('filament.media-picker.modal', [
        'key' => 'album-photos-1', 'multiple' => true, 'confirmLabel' => 'Add to album',
        'scopeAlbumId' => null, 'scope' => null, 'uploadTo' => null,
        'onPicked' => '$wire.call("addPickedPhotos", ids)',
    ])->render();

    expect($html)->toContain('$wire.call(&quot;addPickedPhotos&quot;, ids)')
        ->not->toContain('$wire.call("addPickedPhotos", ids)');
});
