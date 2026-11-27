<?php

use App\Enums\ActivityLevel;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Pages\ThemeSettings;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Filament\Resources\Licenses\Pages\CreateLicense;
use App\Filament\Resources\Licenses\Pages\EditLicense;
use App\Filament\Resources\Licenses\Pages\ListLicenses;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\License;
use App\Models\Photo;
use App\Models\Role as RoleModel;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\DashboardStats;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('gallery');
    $this->before = (int) ActivityEvent::max('id');
});

describe('changes made in the admin', function () {
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

    it('logs creating, editing and deleting a license with readable field names and links to its edit page', function () {
        $admin = actingAsRole(attributes: ['name' => 'Ada Admin']);

        Livewire::test(CreateLicense::class)
            ->fillForm(['name' => 'Studio terms', 'url' => 'https://example.com/terms', 'sort_order' => 3])
            ->call('create')
            ->assertHasNoFormErrors();

        $license = License::where('name', 'Studio terms')->sole();
        $created = changesBy($admin)->where('subject_type', License::class)->sole();
        expect($created->type)->toBe(ActivityType::Created)
            ->and($created->summary())->toBe('Ada Admin created license Studio terms')
            ->and($created->subjectUrl())->toBe(LicenseResource::getUrl('edit', ['record' => $license]));

        afterMergeWindow();
        Livewire::test(EditLicense::class, ['record' => $license->id])
            ->fillForm(['name' => 'Studio terms 2', 'title' => 'Our terms', 'url' => 'https://example.com/new', 'sort_order' => 4, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $updated = changesBy($admin)->where('type', ActivityType::Updated)->where('subject_type', License::class)->sole();
        expect($updated->details())->toContain(
            'Short name: Studio terms → Studio terms 2',
            'Full name: empty → Our terms',
            'Link to the license text: https://example.com/terms → https://example.com/new',
            'Position: 3 → 4',
            'Active: yes → no',
        )
            ->and($updated->subjectUrl())->toBe(LicenseResource::getUrl('edit', ['record' => $license]));

        Livewire::test(ListLicenses::class)->callAction(TestAction::make('delete')->table($license));

        $deleted = changesBy($admin)->where('type', ActivityType::Deleted)->where('subject_type', License::class)->sole();
        expect($deleted->summary())->toBe('Ada Admin deleted license Studio terms 2')
            ->and($deleted->subjectUrl())->toBeNull();
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
});

describe('settings', function () {
    it('names the theme options that changed, not their values', function () {
        actingAsRole(attributes: ['name' => 'Ada']);

        Livewire::test(ThemeSettings::class)
            ->set('data.palette', 'exp')
            ->set('data.header_layout', 'minimal')
            ->call('save');

        expect(newEntries()->where('type', ActivityType::SettingsChanged)->sole()->details())
            ->toBe(['Theme Default: Colours: Palette, Header: Layout']);
    });
});

describe('bulk actions and ordering', function () {
    it('logs moving many photos to the trash as one entry', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create();
        $photos = collect(['Lake', 'Hill', 'Tree', 'Moon', 'Sea'])->map(fn ($name) => galleryPhoto($album, name: $name, width: 60, height: 40));
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
        actingAsRole(attributes: ['name' => 'Ada']);
        $camp = Album::factory()->create(['title' => 'Camp']);
        $best = Album::factory()->create(['title' => 'Best of']);
        $photos = collect(['Lake', 'Hill', 'Tree'])->map(fn ($name) => galleryPhoto($camp, name: $name, width: 60, height: 40));
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
        actingAsRole(attributes: ['name' => 'Ada']);
        $users = User::factory()->count(3)->create();
        $this->before = (int) ActivityEvent::max('id');

        Livewire::test(ListUsers::class)
            ->selectTableRecords($users->modelKeys())
            ->callAction(TestAction::make('delete')->table()->bulk());

        expect(newEntries()->sole()->summary())->toBe('Ada permanently deleted 3 users');
    });

    it('still logs a single item from a bulk action as usual', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $user = User::factory()->create(['name' => 'Ben']);
        $this->before = (int) ActivityEvent::max('id');

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$user->id])
            ->callAction(TestAction::make('delete')->table()->bulk());

        expect(newEntries()->sole()->summary())->toBe('Ada deleted user Ben');
    });

    it('logs dragging photos into order once per album', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create(['title' => 'Trip']);
        $photos = collect(['A', 'B', 'C'])->map(fn ($name) => galleryPhoto($album, name: $name, width: 60, height: 40));
        $this->before = (int) ActivityEvent::max('id');

        $manager = Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class]);
        $manager->call('reorderTable', $photos->reverse()->pluck('id')->values()->all());
        $manager->call('reorderTable', $photos->pluck('id')->all());

        expect(newEntries()->sole()->summary())->toBe('Ada reordered the photos in Trip');
    });

    it('logs dragging albums into order once per level', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $parent = Album::factory()->create(['title' => 'Holidays']);
        $first = Album::factory()->create(['parent_id' => $parent->id, 'sort_order' => 1]);
        $second = Album::factory()->create(['parent_id' => $parent->id, 'sort_order' => 2]);
        $this->before = (int) ActivityEvent::max('id');

        Livewire::test(ListAlbums::class)->call('reorderTable', [$parent->id, $second->id, $first->id]);

        expect(newEntries()->sole()->summary())->toBe('Ada reordered the albums in Holidays');
    });
});

describe('security events', function () {
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
});

describe('logins and visits', function () {
    it('records a login', function () {
        $user = User::factory()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

        expect(ActivityEvent::where('type', ActivityType::Login)->sole())
            ->user_id->toBe($user->id);
    });

    it('records guest album views anonymously, once per day and album', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();

        $this->get(route('albums.show', $album));
        $this->get(route('albums.show', $album));

        $event = ActivityEvent::where('type', ActivityType::AlbumView)->sole();
        expect($event->user_id)->toBeNull()
            ->and($event->album_id)->toBe($album->id)
            ->and($event->visitor)->toHaveLength(64)
            ->and($event->visitor)->not->toContain('127.0.0.1');
    });

    it('counts distinct members per day for the calendar', function () {
        $this->travelTo(Carbon::parse('2026-11-13 12:00'));
        [$anna, $ben] = User::factory()->count(2)->create();

        ActivityEvent::factory()->for($anna)->create(['created_at' => '2026-11-12 08:00']);
        ActivityEvent::factory()->for($anna)->create(['created_at' => '2026-11-12 20:00']);
        ActivityEvent::factory()->for($ben)->create(['created_at' => '2026-11-12 09:00']);
        ActivityEvent::factory()->for($ben)->create(['created_at' => '2026-11-10 09:00']);
        ActivityEvent::factory()->guestView()->create(['created_at' => '2026-11-12 10:00']);

        $calendar = DashboardStats::calendar();
        $days = collect($calendar['weeks'])->flatten(1)->keyBy(fn ($day) => $day['date']->toDateString());

        expect($days['2026-11-12']['count'])->toBe(2)
            ->and($days['2026-11-12']['level'])->toBe(4)
            ->and($days['2026-11-10']['level'])->toBe(2)
            ->and($days['2026-11-14']['future'])->toBeTrue()
            ->and($calendar['activeDays'])->toBe(2)
            ->and($calendar['people'])->toBe(2)
            ->and(DashboardStats::calendar(DashboardStats::GUESTS)['people'])->toBe(1);
    });

    it('ranks the most viewed albums of the last 30 days', function () {
        $this->freezeTime();
        [$popular, $quiet, $old] = Album::factory()->count(3)->create();

        ActivityEvent::factory()->count(3)->guestView()->for($popular)->create();
        ActivityEvent::factory()->guestView()->for($quiet)->create();
        ActivityEvent::factory()->count(5)->guestView()->for($old)->create(['created_at' => now()->subDays(31)]);

        expect(DashboardStats::popularAlbums(userWithRole())->map(fn ($row) => [$row->album->id, $row->views])->all())
            ->toBe([[$popular->id, 3], [$quiet->id, 1]]);
    });

    it('prunes entries older than the retention period', function () {
        $this->freezeTime();
        config(['gallery.activity_retention_days' => 30]);
        $kept = ActivityEvent::factory()->create(['created_at' => now()->subDays(29)]);
        ActivityEvent::factory()->create(['created_at' => now()->subDays(31)]);

        $this->artisan('model:prune', ['--model' => [ActivityEvent::class]]);

        expect(ActivityEvent::where('type', ActivityType::Login)->pluck('id')->all())->toBe([$kept->id]);
    });
});

describe('the detail level', function () {
    it('records only what the chosen detail level includes', function (ActivityLevel $level, bool $changes, bool $logins, bool $views, bool $security) {
        User::factory()->create(['email' => 'anna@example.com']);
        $this->post(route('login'), ['email' => 'anna@example.com', 'password' => 'wrong']);
        $admin = actingAsRole();
        config(['gallery.activity_log_level' => $level->value]);
        $before = (int) ActivityEvent::max('id');
        $album = Album::factory()->visibility(Visibility::Public)->create();

        $album->update(['title' => 'Renamed']);
        event(new Login('web', $admin, false));
        $this->get(route('albums.show', $album));
        auth()->logout();
        $this->post(route('login'), ['email' => 'anna@example.com', 'password' => 'wrong']);

        $recorded = fn (array $types) => ActivityEvent::where('id', '>', $before)->whereIn('type', $types)->exists();

        expect($recorded([ActivityType::Created, ActivityType::Updated]))->toBe($changes)
            ->and($recorded([ActivityType::Login]))->toBe($logins)
            ->and($recorded([ActivityType::AlbumView]))->toBe($views)
            ->and($recorded([ActivityType::LoginFailed]))->toBe($security);
    })->with([
        'off' => [ActivityLevel::Off, false, false, false, false],
        'changes only' => [ActivityLevel::Changes, true, false, false, true],
        'changes and logins' => [ActivityLevel::ChangesAndLogins, true, true, false, true],
        'everything' => [ActivityLevel::All, true, true, true, true],
    ]);

    it('keeps the choice of installs that only had the old on/off switch', function () {
        Setting::put(SiteSettings::ACTIVITY_LOG, false);
        SiteSettings::apply();
        expect(SiteSettings::activityLevel())->toBe(ActivityLevel::Off);

        Setting::put(SiteSettings::ACTIVITY_LOG, true);
        SiteSettings::apply();
        expect(SiteSettings::activityLevel())->toBe(ActivityLevel::All);
    });
});
