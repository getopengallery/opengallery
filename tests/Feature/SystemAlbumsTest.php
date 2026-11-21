<?php

use App\Enums\Role;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

it('gives everyone their own "Unsorted uploads" and "Page images"', function (string $target) {
    [$anna, $ben] = [User::factory()->create(), User::factory()->create()];
    foreach ([$anna, $ben] as $person) {
        $person->assignRole(Role::Uploader->value);
    }

    $this->actingAs($anna);
    $annas = Photo::find(uploadPhotoInChunks($target, 'anna.jpg')->assertOk()->json('photo'));
    $this->actingAs($ben);
    $bens = Photo::find(uploadPhotoInChunks($target, 'ben.jpg')->assertOk()->json('photo'));

    expect($annas->albums->sole())->owner_id->toBe($anna->id)->system_album->toBe($target)
        ->and($bens->albums->sole())->owner_id->toBe($ben->id)->system_album->toBe($target);
})->with([Album::INBOX, Album::PAGE_IMAGES]);

it('uses the "Unsorted uploads" a parallel upload has just created', function () {
    $owner = User::factory()->create();
    $createdByOtherRequest = null;
    Album::creating(function (Album $album) use ($owner, &$createdByOtherRequest) {
        if ($createdByOtherRequest === null && $album->system_album === Album::INBOX) {
            $createdByOtherRequest = Album::factory()->createQuietly(['owner_id' => $owner->id, 'system_album' => Album::INBOX, 'slug' => 'inbox-of-the-other-request']);
        }
    });

    $inbox = Album::inbox($owner);

    expect($inbox->id)->toBe($createdByOtherRequest->id)
        ->and(Album::where('owner_id', $owner->id)->where('system_album', Album::INBOX)->count())->toBe(1);
});

it('lists someone\'s "Unsorted uploads" among their own albums, not other people\'s', function () {
    [$anna, $ben] = [User::factory()->create(), User::factory()->create()];
    $annasInbox = Album::inbox($anna);
    $bensInbox = Album::inbox($ben);
    $this->actingAs($anna->assignRole(Role::Uploader->value));

    Livewire::test(ListAlbums::class)
        ->assertCanSeeTableRecords([$annasInbox])
        ->assertCanNotSeeTableRecords([$bensInbox]);
});

it('takes photos out of "Unsorted uploads" when they are added to an album in the media library', function () {
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $this->actingAs($admin);
    $photo = Photo::factory()->inAlbum(Album::inbox($admin))->create(['uploader_id' => $admin->id]);
    $camp = Album::factory()->for($admin, 'owner')->create();

    Livewire::test(ListPhotos::class)
        ->selectTableRecords([$photo->id])
        ->callAction(TestAction::make('addToAlbum')->table()->bulk(), ['album_id' => $camp->id]);

    expect($photo->fresh()->albums->modelKeys())->toBe([$camp->id]);
});

it('moves other people\'s photos out of a former site-wide system album when upgrading', function () {
    [$anna, $ben] = [User::factory()->create(), User::factory()->create()];
    $shared = Album::inbox($anna); // the old site-wide album, owned by whoever needed it first
    $annas = Photo::factory()->inAlbum($shared)->create(['uploader_id' => $anna->id]);
    $bens = Photo::factory()->inAlbum($shared)->create(['uploader_id' => $ben->id]);
    $shared->updateQuietly(['cover_photo_id' => $bens->id]);

    (require database_path('migrations/2026_11_14_215133_give_everyone_their_own_system_albums.php'))->splitSystemAlbums();

    $bensInbox = Album::where('owner_id', $ben->id)->where('system_album', Album::INBOX)->sole();

    expect($annas->fresh()->albums->modelKeys())->toBe([$shared->id])
        ->and($bens->fresh()->albums->modelKeys())->toBe([$bensInbox->id])
        ->and($shared->fresh()->cover_photo_id)->toBe($annas->id)
        ->and($bensInbox->cover_photo_id)->toBe($bens->id)
        ->and($bensInbox->tree_path)->not->toBeNull()
        ->and(Album::inbox($ben)->id)->toBe($bensInbox->id);
});
