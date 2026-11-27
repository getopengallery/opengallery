<?php

use App\Enums\ActivityLevel;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\StatisticsRange;
use App\Enums\Visibility;
use App\Filament\Pages\Statistics;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use App\Support\Statistics\ExposureStats;
use App\Support\Statistics\LibraryStats;
use App\Support\Statistics\MemberActivityStats;
use App\Support\Statistics\VisitStats;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('gallery');
});

describe('access', function () {
    it('opens for people with "view statistics" and is forbidden for everyone else', function (Role|array $type, bool $allowed) {
        $this->actingAs(userWithRoleOrPermissions($type));

        $response = $this->get(Statistics::getUrl());

        $allowed ? $response->assertOk()->assertSee('Disk space') : $response->assertForbidden();
    })->with([
        'admin' => [Role::Admin, true],
        'super-admin' => [Role::SuperAdmin, true],
        'moderator, who sees the dashboard but not the statistics' => [Role::Moderator, false],
        'uploader' => [Role::Uploader, false],
        'plain user' => [Role::User, false],
        'analyst with the dashboard permission only' => [[Permission::AccessAdmin, Permission::ViewActivity], false],
        'custom role with the permission' => [[Permission::AccessAdmin, Permission::ViewStatistics], true],
        'the permission without access to the admin area' => [[Permission::ViewStatistics], false],
    ]);

    it('sends guests to the login', function () {
        $this->get(Statistics::getUrl())->assertRedirect(route('login'));
    });
});

describe('the page', function () {
    beforeEach(function () {
        $this->travelTo('2026-11-19 12:00'); // a Thursday
        $this->admin = actingAsRole(attributes: ['last_login_at' => now()]);
    });

    it('shows each period in the buckets of its charts', function (int $days, int $buckets, string $label) {
        Livewire::test(Statistics::class)
            ->call('setRange', $days)
            ->assertSet('range', $days)
            ->assertSee($label)
            ->assertSee('Most viewed albums')
            ->assertSee('Who can see what');

        expect(VisitStats::series(StatisticsRange::from($days)))->toHaveCount($buckets);
    })->with([
        'a week, per day' => [7, 7, 'Last 7 days'],
        'a month, per day' => [30, 30, 'Last 30 days'],
        'a quarter, per week' => [90, 14, 'Last 90 days'],
        'a year, per month' => [365, 12, 'Last 12 months'],
    ]);

    it('takes the period from the address and falls back to 30 days for one it does not know', function () {
        Livewire::withQueryParams(['range' => 90])->test(Statistics::class)->assertSet('range', 90);

        $unknown = Livewire::withQueryParams(['range' => 5])->test(Statistics::class)->assertOk();
        expect($unknown->instance()->period())->toBe(StatisticsRange::Month);

        Livewire::test(Statistics::class)->call('setRange', 5)->assertSet('range', 30);
    });

    it('switches the calendar between members and guests, and shows members for anything else', function () {
        Livewire::test(Statistics::class)
            ->assertSet('calendar', VisitStats::MEMBERS)
            ->assertSee('No members were active in the last year.')
            ->call('setCalendar', 'guests')
            ->assertSet('calendar', VisitStats::GUESTS)
            ->assertSee('No guest visits in the last year.')
            ->call('setCalendar', 'everyone')
            ->assertSet('calendar', VisitStats::MEMBERS);
    });

    it('explains what is missing when the activity level is too low', function (ActivityLevel $level, array $seen, array $notSeen) {
        config(['gallery.activity_log_level' => $level->value]);

        $page = Livewire::test(Statistics::class);

        foreach ($seen as $text) {
            $page->assertSee($text);
        }
        foreach ($notSeen as $text) {
            $page->assertDontSee($text);
        }
    })->with([
        'off' => [ActivityLevel::Off, ['does not record logins, album views or downloads', 'is turned off in Settings', 'Album views are not recorded'], []],
        'changes only' => [ActivityLevel::Changes, ['does not record logins, album views or downloads', 'records only changes', 'Album views are not recorded'], []],
        'changes and logins' => [ActivityLevel::ChangesAndLogins, ['records logins but not album views or downloads', 'Album views are not recorded'], ['does not record logins, album views or downloads']],
        'everything' => [ActivityLevel::All, [], ['does not record', 'records logins but not', 'records only changes', 'Album views are not recorded']],
    ]);

    it('warns that a period is longer than what the activity log keeps', function () {
        config(['gallery.activity_retention_days' => 30]);

        Livewire::test(Statistics::class)
            ->call('setRange', 30)->assertDontSee('keeps entries for 30 days')
            ->call('setRange', 90)->assertSee('keeps entries for 30 days, so older visits are no longer counted.');
    });

    it('counts views, logins and downloads of the period only', function () {
        $album = Album::factory()->create();
        $photo = Photo::factory()->inAlbum($album)->create();
        ActivityEvent::factory()->for(User::factory())->create(['type' => ActivityType::AlbumView, 'album_id' => $album->id]);
        ActivityEvent::factory()->guestView()->for($album)->create();
        ActivityEvent::factory()->create(); // a login
        photoDownloadEvent($photo, at: now()->subDay());
        ActivityEvent::factory()->create(['type' => ActivityType::ZipDownload, 'album_id' => $album->id, 'created_at' => now()->subDays(6)]);
        ActivityEvent::factory()->guestView()->for($album)->create(['created_at' => now()->subDays(8)]);

        expect(VisitStats::totals(StatisticsRange::Week))->toBe(['views' => 2, 'guestViews' => 1, 'logins' => 1, 'downloads' => 2])
            ->and(VisitStats::totals(StatisticsRange::Month))->toBe(['views' => 3, 'guestViews' => 2, 'logins' => 1, 'downloads' => 2]);

        $page = Livewire::test(Statistics::class)->call('setRange', 7);

        $page->assertSee('50% by guests');
        expect(preg_match_all('/og-trend-value og-num">(\d+)<\/span>\s*<span class="og-trend-label">([^<]+)</', $page->html(), $headlines, PREG_SET_ORDER))->toBeGreaterThan(2)
            ->and(array_map(fn (array $match) => [(int) $match[1], $match[2]], array_slice($headlines, 0, 3)))->toBe([[2, 'album views'], [2, 'downloads'], [1, 'login']]);
    });
});

describe('album rankings', function () {
    beforeEach(function () {
        $this->freezeTime();
    });

    it('lists the most viewed albums of the period, with zip downloads, and only those the viewer may list', function () {
        $open = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Open day']);
        $private = Album::factory()->visibility(Visibility::Private)->create(['title' => 'Private camp']);
        $recent = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Ten days ago']);
        $ancient = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Ancient history']);
        ActivityEvent::factory()->count(3)->guestView()->for($open)->create();
        ActivityEvent::factory()->create(['type' => ActivityType::ZipDownload, 'album_id' => $open->id, 'user_id' => null, 'visitor' => 'x']);
        ActivityEvent::factory()->count(5)->guestView()->for($private)->create();
        ActivityEvent::factory()->count(2)->guestView()->for($recent)->create(['created_at' => now()->subDays(10)]);
        ActivityEvent::factory()->count(9)->guestView()->for($ancient)->create(['created_at' => now()->subDays(40)]);
        $admin = userWithRole(Role::Admin);
        $analyst = userWithPermissions('statistics analyst', [Permission::AccessAdmin, Permission::ViewStatistics]);
        $rank = fn (StatisticsRange $range, User $viewer) => VisitStats::topAlbums($range, $viewer)->map(fn ($row) => [$row->album->title, $row->views, $row->downloads])->all();

        expect($rank(StatisticsRange::Month, $admin))->toBe([['Private camp', 5, 0], ['Open day', 3, 1], ['Ten days ago', 2, 0]])
            ->and($rank(StatisticsRange::Month, $analyst))->toBe([['Open day', 3, 1], ['Ten days ago', 2, 0]])
            ->and($rank(StatisticsRange::Week, $admin))->toBe([['Private camp', 5, 0], ['Open day', 3, 1]])
            ->and(VisitStats::topAlbums(StatisticsRange::Month, $admin, 1))->toHaveCount(1);

        $this->actingAs($analyst);
        Livewire::test(Statistics::class)
            ->assertSee('Open day')
            ->assertSee('1 zip download')
            ->assertDontSee('Private camp')
            ->assertDontSee('Ancient history');
    });

    it('lists albums nobody opened, leaving out albums created during the period', function () {
        $viewed = Album::factory()->create(['created_at' => now()->subDays(100)]);
        $busy = Album::factory()->create(['title' => 'Many photos', 'created_at' => now()->subDays(100)]);
        $empty = Album::factory()->create(['title' => 'Empty one', 'created_at' => now()->subDays(100)]);
        $lapsed = Album::factory()->create(['title' => 'Last seen long ago', 'created_at' => now()->subDays(100)]);
        $fresh = Album::factory()->create(['title' => 'Brand new', 'created_at' => now()->subDays(5)]);
        Photo::factory()->count(2)->inAlbum($busy)->create();
        ActivityEvent::factory()->guestView()->for($viewed)->create(['created_at' => now()->subDays(2)]);
        ActivityEvent::factory()->guestView()->for($lapsed)->create(['created_at' => now()->subDays(40)]);
        // Other entries about an album are no views.
        ActivityEvent::factory()->create(['type' => ActivityType::ZipDownload, 'album_id' => $empty->id]);

        $unopened = VisitStats::unopenedAlbums(StatisticsRange::Month);

        expect($unopened['count'])->toBe(3)
            ->and($unopened['albums']->pluck('title')->first())->toBe('Many photos')
            ->and($unopened['albums']->pluck('id')->all())->toContain($empty->id, $lapsed->id)
            ->and($unopened['albums']->pluck('id')->all())->not->toContain($viewed->id, $fresh->id);

        $this->actingAs(userWithRole(Role::Admin));
        Livewire::test(Statistics::class)->assertSee('Many photos')->assertSee('3 albums older than this period were not opened in it.')->assertDontSee('Brand new');
    });

    it('lists the most downloaded photos of the period', function () {
        $album = Album::factory()->create();
        [$most, $some, $old] = Photo::factory()->count(3)->sequence(['title' => 'Sunset'], ['title' => 'Harbour'], ['title' => 'Last year'])->create();
        foreach ([$most, $some, $old] as $photo) {
            $album->addPhotos($photo);
        }
        foreach (range(1, 3) as $i) {
            photoDownloadEvent($most);
        }
        photoDownloadEvent($some, User::factory()->create());
        photoDownloadEvent($old, at: now()->subDays(40));
        ActivityEvent::factory()->create(['type' => ActivityType::ZipDownload, 'album_id' => $album->id]);

        expect(VisitStats::topDownloads(StatisticsRange::Month)->map(fn ($row) => [$row->photo->title, $row->downloads])->all())
            ->toBe([['Sunset', 3], ['Harbour', 1]]);

        $this->actingAs(userWithRole(Role::Admin));
        Livewire::test(Statistics::class)->assertSee('Sunset')->assertSee('3 downloads')->assertDontSee('Last year');
    });
});

describe('disk space', function () {
    it('adds generated sizes to the originals and counts what compression saved', function () {
        $this->freezeTime();
        $ada = User::factory()->create(['name' => 'Ada']);
        $camp = Album::factory()->create(['title' => 'Camp']);
        $bestOf = Album::factory()->create(['title' => 'Best of']);
        photoWithStoredSizes($camp, 1000, ['thumb' => 100, 'preview' => 200], beforeCompression: 1500, compressed: true, uploader: $ada);
        $pending = photoWithStoredSizes($camp, 2000);
        $pending->albums()->attach($bestOf);
        $trashed = photoWithStoredSizes($bestOf, 500, ['thumb' => 50], compressed: true, uploader: $ada);
        $trashed->delete();

        expect(LibraryStats::storage())->toBe(['originals' => 3500, 'generated' => 350, 'total' => 3850, 'photos' => 3, 'saved' => 500, 'unknownSavings' => 1, 'pendingSizes' => 1])
            ->and(LibraryStats::totals()['bytes'])->toBe(3850)
            // A photo in two albums counts for both, and photos in the trash for none.
            ->and(LibraryStats::storageByAlbum())->toBe([
                ['id' => $camp->id, 'title' => 'Camp', 'photos' => 2, 'bytes' => 3300],
                ['id' => $bestOf->id, 'title' => 'Best of', 'photos' => 1, 'bytes' => 2000],
            ])
            ->and(LibraryStats::storageByUploader())->toBe([
                ['id' => null, 'name' => null, 'photos' => 1, 'bytes' => 2000],
                ['id' => $ada->id, 'name' => 'Ada', 'photos' => 1, 'bytes' => 1300],
            ]);

        $this->actingAs(userWithRole(Role::Admin));
        Livewire::test(Statistics::class)
            ->assertSee('Largest albums')
            ->assertSee('Deleted accounts')
            ->assertSee('Ada')
            ->assertSee('The generated sizes of 1 photo are still being measured in the background.')
            ->assertSee('1 photo was compressed before sizes were recorded, so its savings are not included.');
    });

    it('shows no figures to worry about for an empty library', function () {
        expect(LibraryStats::storage())->toBe(['originals' => 0, 'generated' => 0, 'total' => 0, 'photos' => 0, 'saved' => 0, 'unknownSavings' => 0, 'pendingSizes' => 0]);

        $this->actingAs(userWithRole(Role::Admin));
        Livewire::test(Statistics::class)->assertSee('No photos yet.')->assertDontSee('still being measured');
    });

    it('counts members and photos added per period with running totals', function () {
        $this->travelTo('2026-11-19 12:00');
        $album = Album::factory()->create(['created_at' => '2026-01-01']);
        Photo::factory()->inAlbum($album)->create(['created_at' => '2026-01-01']);
        Photo::factory()->inAlbum($album)->create(['created_at' => '2026-11-18']);
        Photo::factory()->inAlbum($album)->create(['created_at' => '2026-11-19']);
        Photo::factory()->inAlbum($album)->create(['created_at' => '2026-11-19'])->delete();

        $week = LibraryStats::growth(StatisticsRange::Week);

        expect($week)->toHaveCount(7)
            ->and(array_sum(array_column($week, 'photos')))->toBe(2)
            ->and(end($week)['photos'])->toBe(1)
            ->and(end($week)['totalPhotos'])->toBe(3)
            ->and($week[0]['totalPhotos'])->toBe(1);
    });
});

describe('members and exposure', function () {
    it('sorts members by their last login and counts those who never logged in', function () {
        $this->freezeTime();
        actingAsRole(attributes: ['name' => 'Ada Admin', 'last_login_at' => now()]);
        User::factory()->create(['name' => 'Recent Rita', 'last_login_at' => now()->subDays(3)]);
        User::factory()->create(['name' => 'Monthly Max', 'last_login_at' => now()->subDays(20)]);
        User::factory()->create(['name' => 'Quarterly Quinn', 'last_login_at' => now()->subDays(60)]);
        User::factory()->create(['name' => 'Old Otto', 'last_login_at' => now()->subDays(200)]);
        User::factory()->create(['name' => 'Never Nina', 'last_login_at' => null]);

        expect(MemberActivityStats::overview())->toBe(['members' => 6, 'active' => [7 => 2, 30 => 3, 90 => 4], 'inactive' => 1, 'never' => 1])
            // Never first, then the longest ago.
            ->and(MemberActivityStats::leastActive()->pluck('name')->all())->toBe(['Never Nina', 'Old Otto']);

        Livewire::test(Statistics::class)
            ->assertSee('Least active members')
            ->assertSee('Never Nina')
            ->assertSee('Old Otto')
            ->assertDontSee('Recent Rita')
            ->assertSee('never logged in')
            ->assertSee('not seen for over 180 days');
    });

    it('counts the photos anyone can see, not those behind a password, an expired link or a login', function () {
        config(['gallery.public_albums_enabled' => true]);
        $first = Album::factory()->visibility(Visibility::Public)->create();
        $second = Album::factory()->visibility(Visibility::Public)->create();
        $locked = Album::factory()->visibility(Visibility::Public)->create(['password' => 'lake-2026']);
        $expired = Album::factory()->visibility(Visibility::Public)->create(['access_expires_at' => now()->subDay()]);
        $private = Album::factory()->visibility(Visibility::Private)->create();
        $registered = Album::factory()->visibility(Visibility::Registered)->create();
        Photo::factory()->inAlbum($first)->create();
        $both = Photo::factory()->inAlbum($first)->create();
        $second->addPhotos($both);
        Photo::factory()->inAlbum($second)->create();
        Photo::factory()->inAlbum($first)->create()->delete();
        foreach ([$locked, $expired, $private, $registered] as $album) {
            Photo::factory()->inAlbum($album)->create();
        }
        Album::inbox(User::factory()->create()); // a personal system album is left out

        $exposure = ExposureStats::overview();

        expect($exposure)->toMatchArray(['albums' => 6, 'openAlbums' => 3, 'openPhotos' => 3, 'withPassword' => 1, 'lockedOpenAlbums' => 1, 'expired' => 1, 'publicAlbums' => true])
            ->and($exposure['byVisibility'])->toEqual([Visibility::Public->value => 4, Visibility::Registered->value => 1, Visibility::Restricted->value => 0, Visibility::Unlisted->value => 0, Visibility::Private->value => 1]);

        $this->actingAs(userWithRole(Role::Admin));
        Livewire::test(Statistics::class)
            ->assertSee('1 public album has a password; its photos are not counted above.')
            ->assertSee('Access to 1 album has expired.');
    });

    it('shows no photos to visitors while public albums are turned off', function () {
        config(['gallery.public_albums_enabled' => false]);
        Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Public)->create())->create();

        expect(ExposureStats::overview())->toMatchArray(['openAlbums' => 0, 'openPhotos' => 0, 'publicAlbums' => false]);

        $this->actingAs(userWithRole(Role::Admin));
        Livewire::test(Statistics::class)->assertSee('Public albums are turned off in the settings');
    });
});
