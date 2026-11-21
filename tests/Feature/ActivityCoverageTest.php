<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Filament\Pages\ThemeSettings;
use App\Filament\Resources\ActivityEvents\Pages\ManageActivityEvents;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\SocialAccount;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('gallery');
    $this->before = (int) ActivityEvent::max('id');
});

function coverageAdmin(): User
{
    $admin = tap(User::factory()->create(['name' => 'Ada']))->assignRole(Role::Admin->value);
    test()->actingAs($admin);

    return $admin;
}

/** Entries written since the test started. */
function newEntries(): Collection
{
    return ActivityEvent::where('id', '>', test()->before)->orderBy('id')->get();
}

function coveragePhoto(Album $album, string $name): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    imagejpeg(imagecreatetruecolor(60, 40), $path);

    return app(AddPhotoToAlbum::class)->handle($album, $path, $name);
}

// ---- Settings ---------------------------------------------------------------

it('records turning the activity log off, before it stops recording', function () {
    coverageAdmin();

    Livewire::test(ActivityLogSettings::class)->fillForm(['activity_level' => 'off'])->call('save')->assertHasNoFormErrors();

    expect(newEntries()->where('type', ActivityType::SettingsChanged)->sole()->details())->toBe(['Activity log detail']);

    // Turning it back on is recorded too.
    Livewire::test(ActivityLogSettings::class)->fillForm(['activity_level' => 'all'])->call('save');
    expect(newEntries()->where('type', ActivityType::SettingsChanged))->toHaveCount(2);
});

it('names the theme options that changed, not their values', function () {
    coverageAdmin();

    Livewire::test(ThemeSettings::class)
        ->set('data.palette', 'exp')
        ->set('data.header_layout', 'minimal')
        ->call('save');

    expect(newEntries()->where('type', ActivityType::SettingsChanged)->sole()->details())
        ->toBe(['Theme Default: Colours: Palette, Header: Layout']);
});

// ---- Bulk actions -------------------------------------------------------------

it('logs moving many photos to the trash as one entry', function () {
    coverageAdmin();
    $album = Album::factory()->create();
    $photos = collect(['Lake', 'Hill', 'Tree', 'Moon', 'Sea'])->map(fn ($name) => coveragePhoto($album, $name));
    $this->before = (int) ActivityEvent::max('id');

    Livewire::test(ListPhotos::class)
        ->selectTableRecords($photos->pluck('id')->all())
        ->callAction(TestAction::make('delete')->table()->bulk());

    $entry = newEntries()->sole();
    expect(Photo::onlyTrashed()->count())->toBe(5)
        ->and($entry->summary())->toBe('Ada moved 5 photos to the trash')
        ->and($entry->details())->toBe(['Sea, Moon, Tree and 2 more', 'Can be restored from the trash']);
});

it('logs adding and removing photos in the media library once for the album, like in the album', function () {
    coverageAdmin();
    $camp = Album::factory()->create(['title' => 'Camp']);
    $best = Album::factory()->create(['title' => 'Best of']);
    $photos = collect(['Lake', 'Hill', 'Tree'])->map(fn ($name) => coveragePhoto($camp, $name));
    $this->before = (int) ActivityEvent::max('id');

    Livewire::test(ListPhotos::class)
        ->selectTableRecords($photos->pluck('id')->all())
        ->callAction(TestAction::make('addToAlbum')->table()->bulk(), ['album_id' => $best->id]);
    Livewire::test(ListPhotos::class)
        ->selectTableRecords($photos->pluck('id')->all())
        ->callAction(TestAction::make('removeFromAlbum')->table()->bulk(), ['album_id' => $camp->id]);

    expect(newEntries()->map->summary()->all())->toBe([
        'Ada added 3 photos to Best of',
        'Ada removed 3 photos from Camp',
    ]);
});

it('logs a bulk delete of users as one entry', function () {
    coverageAdmin();
    $users = User::factory()->count(3)->create();
    $this->before = (int) ActivityEvent::max('id');

    Livewire::test(ListUsers::class)
        ->selectTableRecords($users->modelKeys())
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(newEntries()->sole()->summary())->toBe('Ada permanently deleted 3 users');
});

it('still logs a single item from a bulk action as usual', function () {
    coverageAdmin();
    $user = User::factory()->create(['name' => 'Ben']);
    $this->before = (int) ActivityEvent::max('id');

    Livewire::test(ListUsers::class)
        ->selectTableRecords([$user->id])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(newEntries()->sole()->summary())->toBe('Ada deleted user Ben');
});

// ---- Order --------------------------------------------------------------------

it('logs dragging photos into order once per album', function () {
    coverageAdmin();
    $album = Album::factory()->create(['title' => 'Trip']);
    $photos = collect(['A', 'B', 'C'])->map(fn ($name) => coveragePhoto($album, $name));
    $this->before = (int) ActivityEvent::max('id');

    $manager = Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class]);
    $manager->call('reorderTable', $photos->reverse()->pluck('id')->values()->all());
    $manager->call('reorderTable', $photos->pluck('id')->all());

    expect(newEntries()->sole()->summary())->toBe('Ada reordered the photos in Trip');
});

it('logs dragging albums into order once per level', function () {
    coverageAdmin();
    $parent = Album::factory()->create(['title' => 'Holidays']);
    $first = Album::factory()->create(['parent_id' => $parent->id, 'sort_order' => 1]);
    $second = Album::factory()->create(['parent_id' => $parent->id, 'sort_order' => 2]);
    $this->before = (int) ActivityEvent::max('id');

    Livewire::test(ListAlbums::class)->call('reorderTable', [$parent->id, $second->id, $first->id]);

    expect(newEntries()->sole()->summary())->toBe('Ada reordered the albums in Holidays');
});

// ---- Security -----------------------------------------------------------------

it('logs failed logins without storing the e-mail address or IP', function () {
    $anna = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);

    $this->post(route('login'), ['email' => 'anna@example.com', 'password' => 'wrong']);
    $this->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'wrong']);

    [$known, $unknown] = newEntries()->where('type', ActivityType::LoginFailed)->values()->all();
    expect($known->summary())->toBe('Someone used a wrong password for Anna')
        ->and($known->subject_id)->toBe($anna->id)
        ->and($unknown->summary())->toBe('Someone tried to log in with an unknown e-mail address')
        ->and($known->visitor)->toHaveLength(64)
        ->and(json_encode(newEntries()))->not->toContain('nobody@example.com', '127.0.0.1');
});

it('logs a lockout after too many attempts', function () {
    User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);

    foreach (range(1, 6) as $attempt) {
        $this->post(route('login'), ['email' => 'anna@example.com', 'password' => 'wrong']);
    }

    expect(newEntries()->where('type', ActivityType::LockedOut)->first()->summary())
        ->toBe('Someone was locked out after too many login attempts for Anna');
});

it('logs a password reset once, as a security event', function () {
    $anna = User::factory()->create(['name' => 'Anna']);
    $token = Password::createToken($anna);
    $this->before = (int) ActivityEvent::max('id');

    $this->post(route('password.store'), ['token' => $token, 'email' => $anna->email, 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'])
        ->assertSessionHasNoErrors();

    expect(newEntries()->map->summary()->all())->toBe(['Anna reset their password']);
});

it('logs a wrong album password', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Wedding', 'password' => 'secret']);

    $this->post(route('albums.unlock', $album), ['password' => 'nope']);

    expect(newEntries()->where('type', ActivityType::AlbumPasswordFailed)->sole()->summary())
        ->toBe('A guest entered a wrong password for Wedding');
});

it('logs linking and unlinking sign-in accounts', function () {
    $anna = User::factory()->create(['name' => 'Anna']);
    $this->actingAs($anna);
    $this->before = (int) ActivityEvent::max('id');

    $anna->socialAccounts()->create(['provider' => 'github', 'provider_user_id' => '42']);
    $this->delete(route('linked-accounts.destroy', 'github'));

    expect(SocialAccount::count())->toBe(0)
        ->and(newEntries()->map->summary()->all())->toBe([
            'Anna linked a Github account to their account',
            'Anna unlinked a Github account from their account',
        ]);
});

it('records security events at the "changes only" level and shows them under their own kind', function () {
    config(['gallery.activity_log_level' => 'changes']);
    User::factory()->create(['email' => 'anna@example.com']);

    $this->post(route('login'), ['email' => 'anna@example.com', 'password' => 'wrong']);
    $failed = newEntries()->where('type', ActivityType::LoginFailed)->sole();

    coverageAdmin();
    Livewire::test(ManageActivityEvents::class)
        ->filterTable('kind', 'security')
        ->assertCanSeeTableRecords([$failed]);
});

it('records nothing at all when the log is off', function () {
    config(['gallery.activity_log_level' => 'off']);
    User::factory()->create(['email' => 'anna@example.com']);

    $this->post(route('login'), ['email' => 'anna@example.com', 'password' => 'wrong']);

    expect(newEntries())->toBeEmpty();
});
