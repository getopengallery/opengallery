<?php

use App\Enums\Role;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

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
