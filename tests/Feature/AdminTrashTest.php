<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\Pages\ListTrashedAlbums;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Photos\Pages\ListTrashedPhotos;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value)));

it('shows the trash button on a list only while the trash holds something', function () {
    $album = Album::factory()->create();

    Livewire::test(ListAlbums::class)->assertActionHidden('trash');

    $album->delete();

    Livewire::test(ListAlbums::class)
        ->assertActionVisible('trash')
        ->assertActionHasUrl('trash', AlbumResource::getUrl('trash'));
});

it('opens the trash pages at their own address', function (string $resource) {
    $this->get($resource::getUrl('trash'))->assertOk()->assertSee('The trash is empty');
})->with(['albums' => AlbumResource::class, 'photos' => PhotoResource::class]);

it('lists albums in the trash only on the trash page', function () {
    $kept = Album::factory()->create();
    $trashed = tap(Album::factory()->create())->delete();

    Livewire::test(ListAlbums::class)
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$trashed]);
    Livewire::test(ListTrashedAlbums::class)
        ->assertCanSeeTableRecords([$trashed])
        ->assertCanNotSeeTableRecords([$kept]);
});

it('offers only "Move to trash" on the lists and only restore or delete permanently in the trash', function (string $list, string $trash) {
    foreach (['restore', 'forceDelete'] as $action) {
        Livewire::test($list)->assertActionDoesNotExist(TestAction::make($action)->table()->bulk());
    }
    Livewire::test($trash)->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
    Livewire::test($trash)->assertActionExists(TestAction::make('restore')->table()->bulk());
})->with([
    'albums' => [ListAlbums::class, ListTrashedAlbums::class],
    'media' => [ListPhotos::class, ListTrashedPhotos::class],
]);

it('says which photos disappear from the site when an album goes to the trash', function () {
    $album = Album::factory()->create();
    Photo::factory()->count(2)->inAlbum($album)->create();
    Photo::factory()->inAlbum($album)->inAlbum(Album::factory()->create())->create();

    $page = Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])->mountAction('delete');

    expect($page->instance()->getMountedAction()->getModalDescription())
        ->toBe('2 photos are in no other album. They are hidden on the site until you restore the album. You can restore it from the trash.');
});

it('marks photos whose albums are all in the trash as not on the site', function () {
    $album = Album::factory()->create();
    Photo::factory()->inAlbum($album)->create();
    $album->delete();

    Livewire::test(ListPhotos::class)->assertSee('Not on the site');
});

it('opens an album in the trash read-only', function () {
    $album = tap(Album::factory()->create(['title' => 'Camp']))->delete();

    Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
        ->assertSee('This album is in the trash and hidden on the site. Restore it to change it.')
        ->fillForm(['title' => 'Renamed'])
        ->call('save');
    Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
        ->assertActionHidden(TestAction::make('addFromLibrary')->table());

    expect($album->fresh()->title)->toBe('Camp');
});

it('warns that deleting something without a trash cannot be undone', function () {
    $group = Group::factory()->create();

    $page = Livewire::test(ListGroups::class)->mountAction(TestAction::make('delete')->table($group));

    expect($page->instance()->getMountedAction()->getModalDescription())->toBe('This cannot be undone.');
});

describe('photos other people moved to the trash', function () {
    beforeEach(function () {
        $owner = User::factory()->create();
        $album = Album::factory()->for($owner, 'owner')->create(['visibility' => Visibility::Public]);
        $this->othersPhoto = Photo::factory()->inAlbum($album)->create(['title' => 'Trashed by its owner', 'uploader_id' => $owner->id]);
        $this->othersPhoto->delete();

        App\Models\Role::findOrCreate('viewer')->syncPermissions([Permission::AccessAdmin->value]);
        $this->viewer = tap(User::factory()->create())->assignRole('viewer');
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
        // A photo in no album can only be restored by its uploader, even by staff.
        Livewire::test(ListTrashedPhotos::class)
            ->assertCanSeeTableRecords([$this->othersPhoto])
            ->assertCanNotSeeTableRecords([$this->ownTrashed]);
    });
});

describe('the trash count', function () {
    it('is counted once per request, however often the list asks', function () {
        Album::factory()->create()->delete();
        TrashActions::count(AlbumResource::class);
        DB::enableQueryLog();

        $count = TrashActions::count(AlbumResource::class);

        expect($count)->toBe(1);
        expect(DB::getQueryLog())->toBeEmpty();
    });

    it('is counted again when something goes to or leaves the trash', function (bool $trashed, Closure $change, int $expected) {
        $album = Album::factory()->create(['deleted_at' => $trashed ? now() : null]);
        TrashActions::count(AlbumResource::class);

        $change($album);

        expect(TrashActions::count(AlbumResource::class))->toBe($expected);
    })->with([
        'moved to the trash' => [false, fn (Album $album) => $album->delete(), 1],
        'restored' => [true, fn (Album $album) => $album->restore(), 0],
        'deleted permanently' => [true, fn (Album $album) => $album->forceDelete(), 0],
    ]);
});
