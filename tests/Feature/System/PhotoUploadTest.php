<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\CollaboratorRole;
use App\Enums\Role;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Pivots\AlbumPhoto;
use App\Models\User;
use App\Support\PhotoUploads;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('gallery');
    Storage::fake('local');
});

it('puts a photo uploaded in chunks into the target album', function () {
    $admin = actingAsRole();
    $album = Album::factory()->create();

    $photoId = uploadPhotoInChunks("album:{$album->id}", 'Lake view.jpg', chunkSize: 1024)
        ->assertOk()
        ->assertJson(['done' => true])
        ->json('photo');

    $photo = Photo::findOrFail($photoId);
    expect($photo->title)->toBe('Lake view')
        ->and($photo->uploader_id)->toBe($admin->id)
        ->and($photo->width)->toBe(160)
        ->and($album->photos()->pluck('photos.id')->all())->toBe([$photoId])
        ->and(collect(Storage::disk('local')->allFiles(PhotoUploads::CHUNK_DIRECTORY))->filter(fn ($f) => str_ends_with($f, '.part')))->toBeEmpty();
});

it('reports what it has when a chunk arrives at the wrong offset, so the browser can resume', function () {
    actingAsRole();
    $upload = (string) Str::uuid();

    postChunk(['upload' => $upload, 'size' => 10], 'abcd')->assertOk()->assertExactJson(['received' => 4]);
    // A retried chunk the server already has.
    postChunk(['upload' => $upload, 'size' => 10, 'offset' => 0], 'abcd')->assertConflict()->assertExactJson(['received' => 4]);
    postChunk(['upload' => $upload, 'size' => 10, 'offset' => 4], 'efg')->assertOk()->assertExactJson(['received' => 7]);
});

it('answers a repeated last chunk with the same photo instead of a duplicate', function () {
    actingAsRole();
    $upload = (string) Str::uuid();
    $image = UploadedFile::fake()->image('x.jpg', 40, 30)->getContent();

    $first = postChunk(['upload' => $upload], $image)->assertOk()->json('photo');
    $again = postChunk(['upload' => $upload], $image)->assertOk()->json('photo');

    expect($again)->toBe($first)->and(Photo::count())->toBe(1);
});

it('finishes the file when the last chunk is sent again after a failed attempt', function () {
    actingAsRole();
    $album = Album::factory()->create();
    $upload = (string) Str::uuid();
    $image = UploadedFile::fake()->image('x.jpg', 40, 30)->getContent();
    $failOnce = true;
    Photo::creating(function () use (&$failOnce) {
        if ($failOnce) {
            $failOnce = false;

            throw new QueryException('sqlite', 'insert into photos', [], new PDOException('database is locked'));
        }
    });

    postChunk(['upload' => $upload, 'target' => "album:{$album->id}"], $image)->assertServerError();
    $photoId = postChunk(['upload' => $upload, 'target' => "album:{$album->id}"], $image)
        ->assertOk()
        ->assertJson(['done' => true])
        ->json('photo');

    expect(Photo::withTrashed()->pluck('id')->all())->toBe([$photoId])
        ->and($album->photos()->pluck('photos.id')->all())->toBe([$photoId]);
});

it('leaves no photo behind when its images cannot be stored', function () {
    actingAsRole();
    $album = Album::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    file_put_contents($path, UploadedFile::fake()->image('x.jpg', 40, 30)->getContent());
    AlbumPhoto::creating(fn () => throw new RuntimeException('Disk full'));

    expect(fn () => app(AddPhotoToAlbum::class)->handle($album, $path, 'x.jpg'))->toThrow(RuntimeException::class);

    expect(Photo::withTrashed()->count())->toBe(0)
        ->and(Media::count())->toBe(0)
        ->and($album->fresh()->cover_photo_id)->toBeNull();
    @unlink($path);
});

it('keeps a photo out of the library until its images are ready', function () {
    actingAsRole();
    $album = Album::factory()->create();
    $visibleWhileProcessing = null;
    Event::listen(MediaHasBeenAddedEvent::class, function () use (&$visibleWhileProcessing) {
        $visibleWhileProcessing = Photo::count();
    });

    $photoId = uploadPhotoInChunks("album:{$album->id}")->assertOk()->json('photo');

    expect($visibleWhileProcessing)->toBe(0)
        ->and(Photo::find($photoId))->not->toBeNull();
});

it('refuses a file that is not an image once it is complete', function () {
    actingAsRole();

    postChunk(['name' => 'fake.jpg'], 'just some text, not a photo')
        ->assertUnprocessable()
        ->assertJson(['message' => 'Not a supported image.']);

    expect(Photo::count())->toBe(0)
        ->and(collect(Storage::disk('local')->allFiles(PhotoUploads::CHUNK_DIRECTORY))->filter(fn ($f) => str_ends_with($f, '.part')))->toBeEmpty();
});

it('refuses unsupported file types and files over the limit before anything is stored', function () {
    actingAsRole();
    config(['gallery.max_upload_kb' => 1]);

    postChunk(['name' => 'movie.mp4'], 'abc')
        ->assertJsonValidationErrors(['name' => 'This file type is not supported.']);
    postChunk(['size' => 2048], 'abc')
        ->assertJsonValidationErrors(['size' => 'The file is larger than the upload limit of 0 MB.']);

    expect(Storage::disk('local')->allFiles(PhotoUploads::CHUNK_DIRECTORY))->toBeEmpty();
});

it('only accepts uploads into places the person may upload to', function () {
    actingAsRole(Role::Uploader);
    $someoneElses = Album::factory()->create();

    postChunk(['target' => "album:{$someoneElses->id}"], 'abc')->assertForbidden();
    postChunk(['target' => 'somewhere'], 'abc')->assertForbidden();

    auth()->logout();
    postChunk([], 'abc')->assertUnauthorized();
});

it('removes unfinished uploads after a day', function () {
    $disk = Storage::disk('local');
    $disk->put(PhotoUploads::CHUNK_DIRECTORY.'/1/old.part', 'x');
    $disk->put(PhotoUploads::CHUNK_DIRECTORY.'/1/fresh.part', 'x');
    touch($disk->path(PhotoUploads::CHUNK_DIRECTORY.'/1/old.part'), now()->subHours(25)->getTimestamp());

    $this->artisan('gallery:prune-uploads')->assertSuccessful();

    expect($disk->allFiles(PhotoUploads::CHUNK_DIRECTORY))->toBe([PhotoUploads::CHUNK_DIRECTORY.'/1/fresh.part']);
});

it('keeps an empty upload folder that a running upload has just made', function () {
    $part = PhotoUploads::partPath(actingAsRole(), (string) Str::uuid());

    PhotoUploads::prune();

    expect(dirname($part))->toBeDirectory();
});

it('removes an upload folder that has been empty for a day', function () {
    $folder = dirname(PhotoUploads::partPath(actingAsRole(), (string) Str::uuid()));
    touch($folder, now()->subHours(25)->getTimestamp());

    PhotoUploads::prune();

    expect($folder)->not->toBeDirectory();
});

it('sizes chunks to fit the PHP upload limits unless configured', function () {
    expect(PhotoUploads::chunkBytes())->toBeLessThanOrEqual(max(256 * 1024, PhotoUploads::iniBytes((string) ini_get('upload_max_filesize'))));

    config(['gallery.upload_chunk_kb' => 512]);
    expect(PhotoUploads::chunkBytes())->toBe(512 * 1024)
        ->and(PhotoUploads::iniBytes('2M'))->toBe(2 * 1024 * 1024)
        ->and(PhotoUploads::iniBytes('-1'))->toBe(-1);
});

it('lets a member without admin access upload into an album they work on', function () {
    $album = Album::factory()->create();
    $member = addCollaborator($album, CollaboratorRole::Contributor);
    $this->actingAs($member);

    uploadPhotoInChunks('album:'.$album->id)->assertOk()->assertJsonPath('done', true);

    expect($album->photos()->sole()->uploader_id)->toBe($member->id);
});

it('refuses the upload for a member without a role', function () {
    $album = Album::factory()->create();
    $this->actingAs(User::factory()->create());

    uploadPhotoInChunks('album:'.$album->id)->assertForbidden();

    expect($album->photos()->count())->toBe(0);
});

it('keeps the inbox and page images closed to members with a role', function (string $target) {
    $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

    uploadPhotoInChunks($target)->assertForbidden();
})->with(['inbox', 'page-images']);

it('refuses uploads into a trashed album', function () {
    $album = Album::factory()->create();
    $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));
    $album->delete();

    uploadPhotoInChunks('album:'.$album->id)->assertForbidden();
});
