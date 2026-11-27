<?php

use App\Models\Album;
use App\Models\User;
use App\Support\StorageQuota;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

it('limits nobody while storage limits are off', function () {
    $user = giveStorageLimit(User::factory()->create(), 1000);

    expect(StorageQuota::limit($user))->toBeNull()
        ->and(StorageQuota::remaining($user))->toBeNull()
        ->and(StorageQuota::allows($user, PHP_INT_MAX))->toBeTrue();
});

it('gives people without their own limit the default, counted in 1024-based gigabytes', function () {
    useStorageLimits(defaultGb: 3);

    expect(StorageQuota::limit(User::factory()->create()))->toBe(3 * 1024 ** 3);
});

it('follows the site default for new accounts, including ones that sign up themselves', function () {
    useStorageLimits(defaultGb: 5);

    $registered = signUp();

    expect($registered->storage_unlimited)->toBeFalse()
        ->and($registered->storage_quota_bytes)->toBeNull()
        ->and(StorageQuota::limit($registered))->toBe(StorageQuota::gbToBytes(5));
});

it('changes the limit of people on the default with it, but not of those with their own limit or none', function () {
    useStorageLimits(defaultGb: 5);
    $onDefault = User::factory()->create();
    $own = giveStorageLimit(User::factory()->create(), 2000);
    $unlimited = giveStorageLimit(User::factory()->create(), null, unlimited: true);

    useStorageLimits(defaultGb: 8);

    expect(StorageQuota::limit($onDefault))->toBe(StorageQuota::gbToBytes(8))
        ->and(StorageQuota::limit($own))->toBe(2000)
        ->and(StorageQuota::limit($unlimited))->toBeNull();
});

it('lets an unlimited person ignore a limit of their own', function () {
    useStorageLimits();
    $user = giveStorageLimit(User::factory()->create(), 10, unlimited: true);

    expect(StorageQuota::limit($user))->toBeNull()
        ->and(StorageQuota::allows($user, PHP_INT_MAX))->toBeTrue();
});

it('counts the originals and generated files of the person\'s own photos, trashed ones included', function () {
    $album = Album::factory()->create();
    [$user, $other] = User::factory()->count(2)->create();
    photoWithStoredSizes($album, 1000, ['preview' => 200, 'thumb' => 50, 'small' => 5], uploader: $user);
    $trashed = photoWithStoredSizes($album, 400, ['thumb' => 10], uploader: $user);
    photoWithStoredSizes($album, 9999, ['thumb' => 1], uploader: $other);
    $trashed->delete();

    expect(StorageQuota::used($user))->toBe(1255 + 410)
        ->and(StorageQuota::used($other))->toBe(10000)
        ->and(StorageQuota::used(User::factory()->create()))->toBe(0);
});

it('frees the space of a photo as soon as it is deleted for good', function () {
    $album = Album::factory()->create();
    $user = User::factory()->create();
    $big = photoWithStoredSizes($album, 700, ['thumb' => 100], uploader: $user);
    useStorageLimits();
    giveStorageLimit($user, 1000);

    expect(StorageQuota::remaining($user))->toBe(200);

    $big->delete();
    expect(StorageQuota::remaining($user))->toBe(200);

    $big->forceDelete();
    expect(StorageQuota::used($user))->toBe(0)
        ->and(StorageQuota::remaining($user))->toBe(1000);
});

it('allows a file that exactly fills what is left, but not one byte more, and never reports less than zero left', function () {
    $album = Album::factory()->create();
    $user = User::factory()->create();
    photoWithStoredSizes($album, 600, uploader: $user);
    useStorageLimits();
    giveStorageLimit($user, 1000);

    expect(StorageQuota::allows($user, 400))->toBeTrue()
        ->and(StorageQuota::allows($user, 401))->toBeFalse();

    giveStorageLimit($user, 100);

    expect(StorageQuota::remaining($user))->toBe(0)
        ->and(StorageQuota::allows($user, 1))->toBeFalse();
});

it('names the limit in the message for a file that does not fit', function () {
    useStorageLimits(defaultGb: 2);

    expect(StorageQuota::exceededMessage(User::factory()->create()))
        ->toBe('Not enough storage left. You can store up to 2 GB of photos.');
});
