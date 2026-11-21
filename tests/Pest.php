<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\CollaboratorRole;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class))
    ->in('Feature');

/** The oklch() string AdminTheme pins as shade 600 for $hex. */
function expectedShade(string $hex): string
{
    preg_match('/oklch\(([\d.]+)\s+(.+)\)/', Color::convertToOklch($hex), $m);

    return sprintf('oklch(%.3f %s)', $m[1], $m[2]);
}

/** Gives $user (a new member without admin access by default) a direct role on $album. */
function addCollaborator(Album $album, CollaboratorRole $role, ?User $user = null): User
{
    $user ??= User::factory()->create();
    $album->collaborators()->attach($user, ['role' => $role->value]);

    return $user;
}

/** Gives a new group a role on $album; $members join it. */
function addGroupCollaborator(Album $album, CollaboratorRole $role, User ...$members): Group
{
    $group = Group::factory()->create();
    $group->users()->attach(array_map(fn (User $member) => $member->id, $members));
    $album->collaboratorGroups()->attach($group, ['role' => $role->value]);

    return $group;
}

/** A real photo with its files, uploaded by $uploader into $album. */
function collaborationPhoto(Album $album, ?User $uploader = null, string $name = 'Photo.jpg'): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    imagejpeg(imagecreatetruecolor(240, 160), $path);

    return app(AddPhotoToAlbum::class)->handle($album, $path, $name, $uploader);
}

/**
 * Uploads a generated JPEG through the chunked photo upload endpoint, as the
 * media picker does, and returns the final response.
 *
 * @param  array<string, mixed>  $overrides  request fields to change
 */
function uploadPhotoInChunks(string $target, string $name = 'photo.jpg', ?string $batch = null, int $chunkSize = 2048, array $overrides = []): TestResponse
{
    $image = imagecreatetruecolor(160, 120);
    imagefilledrectangle($image, 0, 0, 80, 60, imagecolorallocate($image, 200, 40, 40));
    ob_start();
    imagejpeg($image, null, 95);
    $bytes = ob_get_clean();

    $upload = (string) Str::uuid();
    $batch ??= (string) Str::uuid();

    foreach (str_split($bytes, $chunkSize) as $index => $chunk) {
        $response = test()->postJson(route('uploads.photos'), [
            'upload' => $upload,
            'batch' => $batch,
            'target' => $target,
            'name' => $name,
            'size' => strlen($bytes),
            'offset' => $index * $chunkSize,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $chunk),
            ...$overrides,
        ]);

        if (! $response->isOk()) {
            return $response;
        }
    }

    return $response;
}
