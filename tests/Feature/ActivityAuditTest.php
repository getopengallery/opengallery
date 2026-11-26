<?php

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Role as RoleModel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('logs a new album with its sharing as one entry', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);
    $family = Group::factory()->create(['name' => 'Family']);

    Livewire::test(CreateAlbum::class)
        ->set('data.title', 'Grandma turns 80')
        ->set('data.owner_id', $admin->id)
        ->set('data.visibility', Visibility::Restricted->value)
        ->set('data.shared_with', ['group:'.$family->id])
        ->call('create')
        ->assertHasNoErrors();

    $event = changesBy($admin)->where('subject_type', Album::class)->sole();
    expect($event->type)->toBe(ActivityType::Created)
        ->and($event->summary())->toBe('Ada Admin created album Grandma turns 80')
        ->and($event->details())->toBe(['Shared with groups: +Family']);
});

it('logs edited album fields with readable before and after values', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);
    $album = Album::factory()->for($admin, 'owner')->create(['title' => 'Camp', 'visibility' => Visibility::Private]);
    afterMergeWindow();

    Livewire::test(EditAlbum::class, ['record' => $album->slug])
        ->set('data.title', 'Summer camp')
        ->set('data.visibility', Visibility::Public->value)
        ->call('save')
        ->assertHasNoErrors();

    $event = changesBy($admin)->where('type', ActivityType::Updated)->sole();
    expect($event->details())->toBe([
        'Title: Camp → Summer camp',
        'Visibility: Private (owner only) → Public',
    ]);
});

it('logs role changes and password resets without storing the password', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);
    $anna = tap(User::factory()->create(['name' => 'Anna']))->assignRole(Role::User->value);
    $moderator = RoleModel::findByName(Role::Moderator->value);
    afterMergeWindow();

    Livewire::test(EditUser::class, ['record' => $anna->id])
        ->set('data.roles', [$moderator->id])
        ->set('data.password', 'a-new-long-password')
        ->call('save')
        ->assertHasNoErrors();

    $event = changesBy($admin)->where('subject_id', $anna->id)->where('type', ActivityType::Updated)->sole();
    expect($event->details())->toContain('Roles: +moderator, −user', 'Password changed')
        ->and(json_encode($event->properties))->not->toContain($anna->fresh()->password);
});

it('logs group membership changes', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);
    $group = Group::factory()->create(['name' => 'Scout leaders']);
    [$anna, $ben] = User::factory()->count(2)->sequence(['name' => 'Anna'], ['name' => 'Ben'])->create();
    $group->users()->attach($ben);
    afterMergeWindow();

    $group->users()->sync([$anna->id]);

    expect(changesBy($admin)->where('subject_type', Group::class)->last()->details())
        ->toBe(['Members: +Anna, −Ben']);
});

it('merges quick successive changes and starts a new entry after the merge window', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);
    $album = Album::factory()->create(['title' => 'One']);
    afterMergeWindow();

    $album->update(['title' => 'Two']);
    $album->update(['title' => 'Draft']);
    afterMergeWindow();
    $album->update(['title' => 'Three']);

    expect(changesBy($admin)->where('type', ActivityType::Updated)->map->details()->values()->all())
        ->toBe([['Title: One → Draft'], ['Title: Draft → Three']]);
});

it('drops a change entry when quick changes cancel each other out', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);
    $group = Group::factory()->create();
    $anna = User::factory()->create(['name' => 'Anna']);
    afterMergeWindow();

    $group->users()->attach($anna);
    $group->users()->detach($anna);

    expect(changesBy($admin)->where('type', ActivityType::Updated))->toBeEmpty();
});

it('logs a batch of photos dropped on the picker as a single upload entry', function () {
    Storage::fake('gallery');
    Storage::fake('local');
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);

    // Chunked uploads finish one photo per request; the batch id keeps them in one entry.
    $batch = (string) Str::uuid();
    uploadPhotoInChunks('inbox', 'a.jpg', $batch)->assertOk();
    uploadPhotoInChunks('inbox', 'b.jpg', $batch)->assertOk();

    $event = changesBy($admin)->where('type', ActivityType::PhotosUploaded)->sole();
    expect($event->summary())->toBe('Ada Admin uploaded 2 photos to Unsorted uploads');

    // A new batch starts a new entry.
    uploadPhotoInChunks('inbox', 'c.jpg')->assertOk();
    expect(changesBy($admin)->where('type', ActivityType::PhotosUploaded))->toHaveCount(2);
});

it('logs deleting an album as recoverable from the trash', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);
    $album = Album::factory()->create(['title' => 'Old trip']);

    $album->delete();

    $event = changesBy($admin)->last();
    expect($event->summary())->toBe('Ada Admin deleted album Old trip')
        ->and($event->details())->toBe(['Moved to the trash, can be restored']);
});

it('logs a self sign-up as the new member creating an account', function () {
    $this->post(route('register'), [
        'name' => 'Clara', 'email' => 'clara@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password',
    ]);

    expect(ActivityEvent::where('type', ActivityType::Created)->where('subject_type', User::class)->sole()->summary())->toBe('Clara created an account');
});

it('logs which settings changed, not their values', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);

    Livewire::test(GeneralSettings::class)
        ->set('data.name', 'Lighthouse Photos')
        ->set('data.show_name', false)
        ->call('save');

    $event = changesBy($admin)->sole();
    expect($event->type)->toBe(ActivityType::SettingsChanged)
        ->and($event->details())->toBe(['Site name, Show site name']);
});

it('records no changes while the activity log is turned off', function () {
    config(['gallery.activity_log_level' => 'off']);
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);

    Album::factory()->create()->update(['title' => 'Renamed']);

    expect(changesBy($admin))->toBeEmpty();
});

it('shows changes in the dashboard feed as plain text', function () {
    $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);
    $album = Album::factory()->create(['title' => 'Before']);
    afterMergeWindow();

    $album->update(['title' => '<b>After</b>']);

    $this->get('/admin')
        ->assertSeeText('Ada Admin')
        ->assertSee('changed album', false)
        ->assertSee('Title: Before → After', false)
        ->assertDontSee('<b>After</b>', false);
});
