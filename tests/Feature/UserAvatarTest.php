<?php

use App\Enums\Role;
use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Support\Avatars;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake(Avatars::DISK));

it('lets users upload a profile picture that is re-encoded as a square', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.jpg', 800, 400)])
        ->assertRedirect(route('profile.edit'));

    $path = $user->fresh()->avatar_path;
    expect($path)->toStartWith('avatars/')->toEndWith('.webp');
    Storage::disk(Avatars::DISK)->assertExists($path);
    [$width, $height] = getimagesize(Storage::disk(Avatars::DISK)->path($path));
    expect([$width, $height])->toBe([Avatars::SIZE, Avatars::SIZE]);
});

it('rejects files that are not raster images', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->createWithContent('me.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
        ->assertSessionHasErrorsIn('avatar', 'avatar');

    expect($user->fresh()->avatar_path)->toBeNull();
});

it('deletes the old file when the picture is replaced or removed', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('a.jpg')]);
    $first = $user->fresh()->avatar_path;

    $this->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('b.jpg')]);
    $second = $user->fresh()->avatar_path;
    Storage::disk(Avatars::DISK)->assertMissing($first);

    $this->delete(route('profile.avatar.destroy'))->assertRedirect(route('profile.edit'));
    expect($user->fresh()->avatar_path)->toBeNull();
    Storage::disk(Avatars::DISK)->assertMissing($second);
});

it('serves pictures to logged-in users only', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('a.jpg')]);
    $url = Avatars::url($user->fresh());

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');

    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

it('falls back to initials drawn locally, without an external avatar service', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $url = (new InitialsAvatarProvider)->get($user);

    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($url, strlen('data:image/svg+xml;base64,'))))->toContain('>AL</text>')
        ->and(filament()->getUserAvatarUrl($user))->toBe($url);
});

it('lets admins change a user\'s picture and logs it without the file name', function () {
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $user = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['avatar_path' => UploadedFile::fake()->image('face.png', 300, 500)])
        ->call('save')
        ->assertHasNoErrors();

    $path = $user->fresh()->avatar_path;
    expect($path)->toEndWith('.webp');
    Storage::disk(Avatars::DISK)->assertExists($path);

    $event = ActivityEvent::query()->where('subject_id', $user->id)->latest('id')->first();
    expect($event->details())->toContain('Profile picture changed')
        ->and(json_encode($event->properties))->not->toContain($path);
});

it('shows the current picture in the admin form', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
    $image = UploadedFile::fake()->image('a.jpg');
    $user = User::factory()->create(['avatar_path' => Avatars::store($image->getRealPath())]);

    $state = Livewire::test(EditUser::class, ['record' => $user->id])->get('data.avatar_path');

    expect(array_values($state))->toBe([$user->avatar_path]);
});

it('shows each user\'s picture in the users list', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
    $image = UploadedFile::fake()->image('a.jpg');
    $user = User::factory()->create(['avatar_path' => Avatars::store($image->getRealPath())]);

    Livewire::test(ListUsers::class)
        ->assertTableColumnStateSet('avatar', Avatars::url($user), $user);
});
