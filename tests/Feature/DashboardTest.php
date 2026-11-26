<?php

use App\Enums\ActivityLevel;
use App\Enums\ActivityType;
use App\Enums\BackgroundJobMode;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\DashboardWidgets;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\ActivityTrends;
use App\Filament\Widgets\AlbumsNeedingAttention;
use App\Filament\Widgets\DashboardWidget;
use App\Filament\Widgets\MembersOverview;
use App\Filament\Widgets\PagesOverview;
use App\Filament\Widgets\PopularAlbums;
use App\Filament\Widgets\QuickActions;
use App\Filament\Widgets\RecentActivity;
use App\Filament\Widgets\RecentUploads;
use App\Filament\Widgets\SiteHealth;
use App\Filament\Widgets\TrashOverview;
use App\Filament\Widgets\YourAlbums;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Page;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\Dashboard\SiteHealth as SiteHealthChecks;
use App\Support\DashboardStats;
use App\Support\SiteSettings;
use Filament\Widgets\AccountWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;

$activity = ['library', 'activity-calendar', 'activity-trends', 'recent-activity', 'popular-albums'];

dataset('user types', [
    'super-admin' => [Role::SuperAdmin, ['quick-actions', 'your-albums', 'recent-uploads', 'albums-attention', 'site-health', 'members', 'pages', ...$activity]],
    'admin' => [Role::Admin, ['quick-actions', 'your-albums', 'recent-uploads', 'albums-attention', 'site-health', 'members', 'pages', ...$activity]],
    'moderator' => [Role::Moderator, ['quick-actions', 'your-albums', 'recent-uploads', 'albums-attention', ...$activity]],
    'uploader' => [Role::Uploader, ['quick-actions', 'your-albums', 'recent-uploads']],
    'admin access only' => [[Permission::AccessAdmin], ['quick-actions']],
    'user manager' => [[Permission::AccessAdmin, Permission::ManageUsers, Permission::ManageGroups], ['quick-actions', 'members']],
    'content editor' => [[Permission::AccessAdmin, Permission::ManagePages], ['quick-actions', 'pages']],
    'site operator' => [[Permission::AccessAdmin, Permission::ManageSettings, Permission::ManageThemes, Permission::ManagePlugins], ['quick-actions', 'site-health']],
    'analyst' => [[Permission::AccessAdmin, Permission::ViewActivity], ['quick-actions', ...$activity]],
    'permissions without admin access' => [[Permission::ViewActivity, Permission::ManageUsers, Permission::UploadPhotos], []],
]);

describe('who sees which widget', function () {
    it('matches the capabilities of each user type', function (Role|array $type, array $expected) {
        $user = userWithRoleOrPermissions($type);
        $this->actingAs($user);

        expect(visibleWidgets())->toBe($expected);

        $expected === []
            ? $this->get('/admin')->assertForbidden()
            : $this->get('/admin')->assertOk()->assertSee(__('Welcome, :name', ['name' => $user->name]));
    })->with('user types');

    it('never leaves an admin user on an empty dashboard', function () {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin]))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Your account can open the media library and the documentation.')
            ->assertSee('Media library')
            ->assertSee('Documentation')
            ->assertDontSee('Settings');
    });

    it('offers only shortcuts the user may follow', function () {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, Permission::ManagePages]));

        Livewire::test(QuickActions::class)
            ->assertSee('New page')
            ->assertDontSee('New album')
            ->assertDontSee('Add user')
            ->assertDontSee('Activity log')
            ->assertDontSee('Your account can open the media library');
    });

    it('offers a new album to those who may create albums', function () {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, Permission::CreateAlbums]));

        Livewire::test(QuickActions::class)
            ->assertSee('New album')
            ->assertSee(AlbumResource::getUrl('create'), false);
    });

    it('shows the trash only while it holds something of the viewer', function () {
        $uploader = userWithRoleOrPermissions(Role::Uploader);
        $this->actingAs($uploader);
        tap(Album::factory()->for(User::factory(), 'owner')->create())->delete();

        expect(TrashOverview::canView())->toBeFalse();

        $album = Album::factory()->for($uploader, 'owner')->create();
        $album->delete();
        Once::flush(); // as in a new request

        Livewire::test(TrashOverview::class)->assertSee('1 album');
    });
});

describe('only what the viewer may see', function () {
    beforeEach(function () {
        $this->private = Album::factory()->create(['title' => 'Private camp', 'visibility' => Visibility::Private]);
        $this->public = Album::factory()->create(['title' => 'Open day', 'visibility' => Visibility::Public]);
        foreach ([$this->private, $this->public] as $album) {
            ActivityEvent::factory()->guestView()->for($album)->create();
        }
    });

    it('leaves private albums out of the most viewed albums for people who cannot open them', function () {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, Permission::ViewActivity]));

        Livewire::test(PopularAlbums::class)
            ->assertSee('Open day')
            ->assertSee(route('albums.show', $this->public))
            ->assertDontSee('Private camp');
    });

    it('lists every album for staff who may see them, linked to the album editor', function () {
        $this->actingAs(userWithRoleOrPermissions(Role::Moderator));

        Livewire::test(PopularAlbums::class)
            ->assertSee('Private camp')
            ->assertSee(AlbumResource::getUrl('edit', ['record' => $this->private]));
    });

    it('leaves entries about hidden albums and photos out of the recent activity', function () {
        $hiddenPhoto = Photo::factory()->inAlbum($this->private)->create(['title' => 'Hidden photo']);
        ActivityEvent::factory()->create(['type' => ActivityType::Updated, 'subject_type' => $hiddenPhoto->getMorphClass(), 'subject_id' => $hiddenPhoto->id, 'properties' => ['label' => 'Hidden photo']]);
        ActivityEvent::factory()->create(['type' => ActivityType::Updated, 'subject_type' => $this->private->getMorphClass(), 'subject_id' => $this->private->id]);
        $analyst = userWithRoleOrPermissions([Permission::AccessAdmin, Permission::ViewActivity]);

        expect(DashboardStats::recent($analyst)->pluck('album_id')->filter()->unique()->values()->all())->toBe([$this->public->id]);

        $this->actingAs($analyst);
        Livewire::test(RecentActivity::class)
            ->assertSee('Open day')
            ->assertDontSee('Private camp')
            ->assertDontSee('Hidden photo');
    });

    it('does not link to admin pages the viewer may not open', function () {
        $member = User::factory()->create(['name' => 'Grace']);
        ActivityEvent::factory()->create(['type' => ActivityType::Updated, 'subject_type' => $member->getMorphClass(), 'subject_id' => $member->id]);

        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, Permission::ViewActivity]));
        Livewire::test(RecentActivity::class)->assertSee('Grace')->assertDontSee(UserResource::getUrl('edit', ['record' => $member]));

        $this->actingAs(userWithRoleOrPermissions(Role::Admin));
        Livewire::test(RecentActivity::class)->assertSee(UserResource::getUrl('edit', ['record' => $member]));
    });

    it('shows a moderator albums needing attention, but uploaders only their own', function () {
        $owner = userWithRoleOrPermissions(Role::Uploader);
        $empty = Album::factory()->for($owner, 'owner')->create(['title' => 'Still empty']);
        $expiring = Album::factory()->for($owner, 'owner')->create(['title' => 'Expiring soon', 'visibility' => Visibility::Registered, 'access_expires_at' => now()->addDays(3)]);
        Photo::factory()->inAlbum($expiring)->create();
        $expiring->update(['cover_photo_id' => $expiring->photos()->first()->id]);

        $this->actingAs(userWithRoleOrPermissions(Role::Moderator));
        Livewire::test(AlbumsNeedingAttention::class)
            ->assertSee('Still empty')
            ->assertSee('Expiring soon')
            ->assertSee('Link expires soon')
            ->assertSee($owner->name);

        $this->actingAs(userWithRoleOrPermissions(Role::Uploader));
        expect(AlbumsNeedingAttention::canView())->toBeFalse();
        Livewire::test(YourAlbums::class)->assertDontSee('Still empty');
    });
});

describe('personal widgets', function () {
    it('points uploaders to photos waiting in "Unsorted uploads" and flags expiring links', function () {
        $uploader = userWithRoleOrPermissions(Role::Uploader);
        Photo::factory()->count(2)->inAlbum(Album::inbox($uploader))->create(['uploader_id' => $uploader->id]);
        Album::factory()->for($uploader, 'owner')->create(['title' => 'Party', 'visibility' => Visibility::Public, 'access_expires_at' => now()->subDay()]);

        $this->actingAs($uploader);
        Livewire::test(YourAlbums::class)
            ->assertSee('2 photos wait in “Unsorted uploads”.')
            ->assertSee('Party')
            ->assertSee('Link expired');
    });

    it('tells people who may only upload where their photos go', function () {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, Permission::UploadPhotos]));

        Livewire::test(YourAlbums::class)->assertSee('Photos you upload wait in “Unsorted uploads”');
    });

    it('shows only the viewer\'s own uploads', function () {
        $uploader = userWithRoleOrPermissions(Role::Uploader);
        $album = Album::factory()->for($uploader, 'owner')->create();
        $mine = Photo::factory()->inAlbum($album)->create(['title' => 'Mine', 'uploader_id' => $uploader->id]);
        Photo::factory()->inAlbum($album)->create(['title' => 'Someone else\'s', 'uploader_id' => User::factory()]);

        $this->actingAs($uploader);
        Livewire::test(RecentUploads::class)
            ->assertSee('Mine')
            ->assertDontSee('Someone else')
            ->assertSee('1 in the last 7 days');
        expect($mine->exists)->toBeTrue();
    });
});

describe('members, pages and site health', function () {
    it('counts accounts waiting, invitations and new members', function () {
        $this->freezeTime();
        User::factory()->create(['awaiting_approval' => true, 'name' => 'Waiting Wanda']);
        User::factory()->create(['created_at' => now()->subDays(40)]);
        DB::table('user_invitation_tokens')->insert([
            ['email' => 'fresh@example.com', 'token' => 'a', 'created_at' => now()->subDay()],
            ['email' => 'old@example.com', 'token' => 'b', 'created_at' => now()->subDays(30)],
        ]);
        $manager = userWithRoleOrPermissions([Permission::AccessAdmin, Permission::ManageUsers]);
        $admin = userWithRoleOrPermissions(Role::Admin);

        $this->actingAs($manager);
        $html = Livewire::test(MembersOverview::class)
            ->assertSee('1 account waits for approval')
            ->assertSee('1 invitation expired before it was used')
            ->assertSee('1 invitation has not been accepted yet')
            ->assertSee('Waiting Wanda')
            ->assertSee(UserResource::getUrl('index', ['filters' => ['awaiting_approval' => ['isActive' => true]]]), false)
            ->html();

        // Admins are newest but cannot be edited by a manager with fewer rights.
        expect($html)->toContain($admin->name)
            ->not->toContain(UserResource::getUrl('edit', ['record' => $admin]));

        // The figure links open the list already filtered.
        $this->get(UserResource::getUrl('index', ['filters' => ['awaiting_approval' => ['isActive' => true]]]))
            ->assertOk()->assertSee('Waiting Wanda')->assertDontSee($admin->name);
    });

    it('shows drafts and recently edited pages', function () {
        Page::factory()->draft()->create(['title' => 'Coming soon']);
        Page::factory()->create(['title' => 'About us']);

        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, Permission::ManagePages]));
        Livewire::test(PagesOverview::class)
            ->assertSee('Published: 1. Drafts: 1. For members only: 0.')
            ->assertSee('Coming soon')
            ->assertSee('Draft');
    });

    it('reports problems with a link to what fixes them, and says so when all is well', function () {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, Permission::ManageSettings]));
        config(['gallery.activity_log_level' => ActivityLevel::All->value, 'mail.default' => 'smtp']);

        Livewire::test(SiteHealth::class)->assertSee('checks passed');

        config(['gallery.activity_log_level' => ActivityLevel::Off->value, 'mail.default' => 'mailbox']);
        Livewire::test(SiteHealth::class)
            ->assertSee('The activity log is off')
            ->assertSee('E-mails are not sent')
            ->assertSee('Settings → E-mail');
    });

    it('notices when the background jobs do not run', function () {
        Setting::put(SiteSettings::BACKGROUND_JOBS, BackgroundJobMode::Cron->value);

        $this->actingAs(userWithRoleOrPermissions(Role::Admin));
        Livewire::test(SiteHealth::class)
            ->assertSee('Background jobs do not run')
            ->assertSee('System → Background jobs');
    });

    it('links settings problems to the page that fixes them', function (string $problem, string $page) {
        $this->actingAs(userWithRoleOrPermissions(Role::Admin));

        $url = SiteHealth::describe(['key' => $problem, 'severity' => SiteHealthChecks::WARNING, 'count' => null])['url'];

        expect($url)->toEndWith("/admin/settings/{$page}");
        $this->get($url)->assertOk();
    })->with([
        'e-mail' => ['mail-not-sent', 'email'],
        'background jobs' => ['background-jobs-idle', 'background-jobs'],
        'activity log' => ['activity-log-off', 'activity-log'],
    ]);

    it('reports OPcache only when the web server runs without it', function (?bool $running, bool $reported) {
        expect(SiteHealthChecks::opcacheOff($running))->toBe($reported);
    })->with([
        'off on the web server' => [false, true],
        'on' => [true, false],
        'asked from the command line, where it is usually off' => [null, false],
    ]);

    it('explains how to turn OPcache on', function () {
        $problem = ['key' => 'opcache-off', 'severity' => SiteHealthChecks::WARNING, 'count' => null];

        $described = SiteHealth::describe($problem);

        expect($described['title'])->toBe('OPcache is off');
        expect($described['url'])->toEndWith('doc=user%2Fgetting-started%2Finstallation#speed');
    });
});

it('counts album views and logins per week', function () {
    $this->travelTo('2026-11-19 12:00'); // a Thursday
    $album = Album::factory()->create();
    ActivityEvent::factory()->guestView()->for($album)->create(['created_at' => '2026-11-17 10:00']);
    ActivityEvent::factory()->for(User::factory())->create(['type' => ActivityType::AlbumView, 'album_id' => $album->id, 'created_at' => '2026-11-18 10:00']);
    ActivityEvent::factory()->create(['created_at' => '2026-11-10 10:00']); // a login the week before

    $weeks = DashboardStats::trends();

    expect($weeks)->toHaveCount(12)
        ->and($weeks[11]['start']->toDateString())->toBe('2026-11-16')
        ->and([$weeks[11]['memberViews'], $weeks[11]['guestViews'], $weeks[11]['logins']])->toBe([1, 1, 0])
        ->and($weeks[10]['logins'])->toBe(1);

    $this->actingAs(userWithRoleOrPermissions(Role::Admin));
    // Leads with the last full week; the running week (2 views so far) is drawn hatched.
    Livewire::test(ActivityTrends::class)
        ->assertSeeInOrder(['og-trend-value og-num">0<', 'album views last week', 'og-trend-value og-num">1<', 'login last week'], false)
        ->assertSee('og-trend-col is-current', false);
});

it('keeps the number of queries independent of the size of the library', function (string $widget) {
    $admin = userWithRoleOrPermissions(Role::Admin);
    $this->actingAs($admin);

    $queries = function () use ($widget): int {
        cache()->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($widget)->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $grow = function (int $n) use ($admin) {
        $albums = Album::factory()->count($n)->for($admin, 'owner')->create(['visibility' => Visibility::Public]);
        foreach ($albums as $album) {
            Photo::factory()->inAlbum($album)->create(['uploader_id' => $admin->id]);
            ActivityEvent::factory()->guestView()->for($album)->create();
            ActivityEvent::factory()->for(User::factory())->create(['type' => ActivityType::Updated, 'subject_type' => $album->getMorphClass(), 'subject_id' => $album->id]);
        }
        Page::factory()->count($n)->create();
    };

    // Start above the longest list (12 entries), so both runs show the same kinds of records.
    $grow(13);
    $queries(); // warms up what every request loads once (permissions, settings)
    $small = $queries();
    $grow(30);

    expect($queries())->toBe($small);
})->with([
    'your albums' => YourAlbums::class,
    'recent uploads' => RecentUploads::class,
    'albums needing attention' => AlbumsNeedingAttention::class,
    'members' => MembersOverview::class,
    'pages' => PagesOverview::class,
    'site health' => SiteHealth::class,
    'recent activity' => RecentActivity::class,
    'popular albums' => PopularAlbums::class,
    'trends' => ActivityTrends::class,
]);

describe('extension point', function () {
    it('takes widgets from plugins under a stable key, after the core ones', function () {
        $widget = new class extends DashboardWidget {};
        $registry = app(DashboardWidgets::class);
        $registry->register('my-plugin.stats', $widget::class);

        expect(array_key_last($registry->all()))->toBe('my-plugin.stats')
            ->and($registry->keyOf($widget::class))->toBe('my-plugin.stats')
            ->and(array_keys($registry->all()))->toContain('quick-actions', 'popular-albums');
    });

    it('rejects invalid keys and classes that are not dashboard widgets', function (string $key, string $class) {
        app(DashboardWidgets::class)->register($key, $class);
    })->with([
        'key with a space' => ['my plugin', QuickActions::class],
        'key with capitals' => ['MyPlugin.stats', QuickActions::class],
        'not a widget' => ['my-plugin.user', User::class],
        'a widget without the dashboard base class' => ['my-plugin.plain', AccountWidget::class],
    ])->throws(InvalidArgumentException::class);
});
