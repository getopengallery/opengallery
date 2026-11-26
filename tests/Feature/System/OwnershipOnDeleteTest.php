<?php

use App\Enums\CollaboratorRole;
use App\Models\Album;
use App\Models\Photo;
use App\Support\AccountDeletion;
use App\Support\AlbumDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

it('keeps a user\'s albums when the user row is deleted directly', function () {
    $album = Album::factory()->create();

    $album->owner->delete();

    expect($album->fresh())->not->toBeNull()
        ->and($album->fresh()->owner_id)->toBeNull();
});

it('moves a photo in no other album to its uploader\'s unsorted uploads', function () {
    $album = Album::factory()->create();
    $uploader = addCollaborator($album, CollaboratorRole::Contributor);
    $photo = Photo::factory()->inAlbum($album)->create(['uploader_id' => $uploader->id]);

    $result = $album->removePhotos(Photo::whereKey($photo->id)->get(), moveLastCopies: true);

    expect($result)->toBe(['removed' => 1, 'kept' => 0])
        ->and($photo->fresh()->trashed())->toBeFalse()
        ->and($photo->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($uploader)->id]);
});

it('falls back to the owner\'s unsorted uploads when the uploader is gone', function () {
    $album = Album::factory()->create();
    $photo = Photo::factory()->inAlbum($album)->create(['uploader_id' => null]);

    $album->removePhotos(Photo::whereKey($photo->id)->get(), moveLastCopies: true);

    expect($photo->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($album->owner)->id]);
});

it('still keeps the last copy in place for the admin\'s remove action', function () {
    $album = Album::factory()->create();
    $photo = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);

    expect($album->removePhotos(Photo::whereKey($photo->id)->get()))->toBe(['removed' => 0, 'kept' => 1]);
});

it('keeps collaborators\' uploads when the owner deletes their account with all data', function () {
    $album = Album::factory()->create();
    $collaborator = addCollaborator($album, CollaboratorRole::Contributor);
    $theirs = galleryPhoto($album, $collaborator);
    $ownersPhoto = galleryPhoto($album, $album->owner);

    AccountDeletion::deleteWithAllData($album->owner);

    expect($theirs->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($collaborator)->id])
        ->and(Photo::withTrashed()->find($ownersPhoto->id))->toBeNull();
});

it('keeps collaborators\' uploads when an album is deleted with its photos', function () {
    $album = Album::factory()->create();
    $child = Album::factory()->for($album->owner, 'owner')->create(['parent_id' => $album->id]);
    $collaborator = addCollaborator($album, CollaboratorRole::Contributor);
    $inChild = galleryPhoto($child, $collaborator);
    $album->delete();

    AlbumDeletion::forceDelete($child->fresh(), true, $album->owner);

    expect($inChild->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($collaborator)->id]);
});

it('keeps the album tree when a collaborator deletes their account', function () {
    $album = Album::factory()->create();
    $collaborator = addCollaborator($album, CollaboratorRole::Organizer);
    $subAlbum = Album::factory()->for($album->owner, 'owner')->create(['parent_id' => $album->id]);
    $upload = galleryPhoto($subAlbum, $collaborator);

    AccountDeletion::deleteWithAllData($collaborator);

    expect($subAlbum->fresh())->not->toBeNull()
        ->and($subAlbum->fresh()->owner_id)->toBe($album->owner_id)
        ->and($upload->fresh()->albums->pluck('id')->all())->toBe([$subAlbum->id])
        ->and($upload->fresh()->uploader_id)->toBeNull()
        ->and(DB::table('album_collaborators')->count())->toBe(0);
});
