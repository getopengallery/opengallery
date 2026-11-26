<?php

use App\Enums\ActivityLevel;
use App\Enums\ActivityType;
use App\Enums\BackgroundJobMode;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\DashboardWidgets;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\ActivityTrends;
use App\Filament\Widgets\AlbumsNeedingAttention;
use App\Filament\Widgets\DashboardWidget;
use App\Filament\Widgets\LibraryOverview;
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
use App\Models\Role as RoleModel;
use App\Models\Setting;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use App\Support\Dashboard\SiteHealth as SiteHealthChecks;
use App\Support\DashboardStats;
use App\Support\SiteSettings;
use Filament\Widgets\AccountWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;

$activity = ['library', 'activity-calendar', 'activity-trends', 'recent-activity', 'popular-albums'];

$n = DashboardLayout::NORMAL;
$w = DashboardLayout::WIDE;
$f = DashboardLayout::FULL;

describe('who sees which widget', function () use ($activity) {
    it('shows each user type the widgets that match their capabilities', function (Role|array $type, array $expected) {
        $user = userWithRoleOrPermissions($type);
        $this->actingAs($user);
        ActivityEvent::factory()->guestView()->create();

        expect(visibleWidgets())->toBe($expected);

        if ($expected === []) {
            $this->get('/admin')->assertForbidden();

            return;
        }

        $response = $this->get('/admin')->assertOk()->assertSee(__('Welcome, :name', ['name' => $user->name]));

        in_array('your-albums', $expected, true) ? $response->assertSee('Your albums') : $response->assertDontSee('Your albums');
        in_array('recent-activity', $expected, true) ? $response->assertSee('Recent activity') : $response->assertDontSee('Recent activity')->assertDontSee('of photos stored');
    })->with([
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

    it('never leaves an admin user on an empty dashboard', function () {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin]))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Your account can open the media library and the documentation.')
            ->assertSee('Media library')
            ->assertSee('Documentation')
            ->assertDontSee('Settings');
    });

    it('offers only shortcuts the user may follow', function (array $permissions, string $offered, array $notOffered) {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, ...$permissions]));

        $shortcuts = Livewire::test(QuickActions::class)->assertSee($offered)->assertDontSee('Your account can open the media library');

        foreach ($notOffered as $text) {
            $shortcuts->assertDontSee($text);
        }
    })->with([
        'pages' => [[Permission::ManagePages], 'New page', ['New album', 'Add user', 'Activity log']],
        'albums' => [[Permission::CreateAlbums], 'New album', ['New page', 'Add user', 'Activity log']],
    ]);

    it('links the new album shortcut to the create form', function () {
        $this->actingAs(userWithRoleOrPermissions([Permission::AccessAdmin, Permission::CreateAlbums]));

        Livewire::test(QuickActions::class)->assertSee(AlbumResource::getUrl('create'), false);
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

    it('lists the most viewed albums the viewer may open', function (Role|array $type, bool $seesPrivate) {
        $this->actingAs(userWithRoleOrPermissions($type));

        $popular = Livewire::test(PopularAlbums::class)->assertSee('Open day');

        $seesPrivate
            ? $popular->assertSee('Private camp')->assertSee(AlbumResource::getUrl('edit', ['record' => $this->private]))
            : $popular->assertSee(route('albums.show', $this->public))->assertDontSee('Private camp');
    })->with([
        'staff who may see every album' => [Role::Moderator, true],
        'people who cannot open private albums' => [[Permission::AccessAdmin, Permission::ViewActivity], false],
    ]);

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
        Album::factory()->for($owner, 'owner')->create(['title' => 'Still empty']);
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

describe('an administrator\'s dashboard', function () {
    it('shows the library figures and the activity feed, escaping names and keeping changes as plain text', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp']);
        ActivityEvent::factory()->guestView()->for($album)->create();
        ActivityEvent::factory()->for(User::factory()->create(['name' => '<script>alert(1)</script>']))->create();
        $ada = actingAsRole(attributes: ['name' => 'Ada Admin']);
        $edited = Album::factory()->create(['title' => 'Before']);
        afterMergeWindow();
        $edited->update(['title' => '<b>After</b>']);

        $this->get('/admin')
            ->assertOk()
            ->assertSeeInOrder(['albums', 'photos', 'members', 'groups'])
            ->assertSee('A guest')
            ->assertSee('Summer camp')
            ->assertSee('og-calendar-grid', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false)
            ->assertSeeText($ada->name)
            ->assertSee('changed album', false)
            ->assertSee('Title: Before → After', false)
            ->assertDontSee('<b>After</b>', false);
    });

    it('links each library figure to its list', function (Role|array $type, Closure $urls) {
        $this->actingAs(userWithRoleOrPermissions($type));

        expect(collect((new LibraryOverview)->getFigures())->pluck('url')->all())->toBe($urls());
    })->with([
        'administrator' => [Role::Admin, fn () => [AlbumResource::getUrl(), PhotoResource::getUrl(), UserResource::getUrl(), GroupResource::getUrl(), null]],
        // Albums and media are open to every admin user (scoped to what they may see); users and groups are not.
        'staff who may only watch' => [[Permission::AccessAdmin, Permission::ViewActivity], fn () => [AlbumResource::getUrl(), PhotoResource::getUrl(), null, null, null]],
    ]);
});

describe('personal widgets', function () {
    it('greets uploaders with their albums and ways to add more', function () {
        $uploader = userWithRole(Role::Uploader);
        $camp = Album::factory()->for($uploader, 'owner')->create(['title' => 'Summer camp']);
        Album::inbox($uploader);
        Album::factory()->create(['title' => 'Someone else\'s']);

        $this->actingAs($uploader)->get('/admin')
            ->assertOk()
            ->assertSee('Your albums')
            ->assertSee(AlbumResource::getUrl('edit', ['record' => $camp]))
            ->assertDontSee('Unsorted uploads')
            ->assertDontSee('Someone else&#039;s', false)
            ->assertSee(AlbumResource::getUrl('create'))
            ->assertSee(PhotoResource::getUrl('index', ['action' => 'uploadPhotos']));
    });

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
        Photo::factory()->inAlbum($album)->create(['title' => 'Mine', 'uploader_id' => $uploader->id]);
        Photo::factory()->inAlbum($album)->create(['title' => 'Someone else\'s', 'uploader_id' => User::factory()]);

        $this->actingAs($uploader);
        Livewire::test(RecentUploads::class)
            ->assertSee('Mine')
            ->assertDontSee('Someone else')
            ->assertSee('1 in the last 7 days');
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

        expect($described['title'])->toBe('OPcache is off')
            ->and($described['url'])->toEndWith('doc=user%2Fgetting-started%2Finstallation#speed');
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

describe('the dashboard layout', function () use ($n, $w, $f) {
    beforeEach(function () {
        $this->admin = actingAsRole();
    });

    it('starts each kind of user with the preset that fits what they may see', function (Role|array $type, string $preset, array $expected) {
        $user = userWithRoleOrPermissions($type);
        $this->actingAs($user);

        expect(DashboardLayout::presetFor(app(DashboardLayout::class)->available()))->toBe($preset)
            ->and(defaultsOf($user))->toBe($expected)
            ->and($user->fresh()->dashboard_layout)->toBeNull();
    })->with([
        'super-admin' => [Role::SuperAdmin, 'administrator', ['quick-actions' => $f, 'site-health' => $n, 'members' => $n, 'albums-attention' => $n, 'library' => $f, 'your-albums' => $n, 'recent-uploads' => $n, 'pages' => $n, 'activity-trends' => $f, 'recent-activity' => $w, 'popular-albums' => $n, 'activity-calendar' => $f]],
        'admin' => [Role::Admin, 'administrator', ['quick-actions' => $f, 'site-health' => $n, 'members' => $n, 'albums-attention' => $n, 'library' => $f, 'your-albums' => $n, 'recent-uploads' => $n, 'pages' => $n, 'activity-trends' => $f, 'recent-activity' => $w, 'popular-albums' => $n, 'activity-calendar' => $f]],
        'moderator' => [Role::Moderator, 'moderator', ['quick-actions' => $f, 'albums-attention' => $n, 'your-albums' => $n, 'recent-uploads' => $n, 'activity-trends' => $f, 'recent-activity' => $w, 'popular-albums' => $n, 'library' => $f, 'activity-calendar' => $f]],
        'uploader' => [Role::Uploader, 'uploader', ['quick-actions' => $f, 'your-albums' => $w, 'recent-uploads' => $n]],
        'analyst' => [[Permission::AccessAdmin, Permission::ViewActivity], 'analyst', ['quick-actions' => $f, 'activity-trends' => $f, 'recent-activity' => $w, 'popular-albums' => $n, 'library' => $f, 'activity-calendar' => $f]],
        'user manager' => [[Permission::AccessAdmin, Permission::ManageUsers, Permission::ManageGroups], 'user-manager', ['quick-actions' => $f, 'members' => $f]],
        'site operator' => [[Permission::AccessAdmin, Permission::ManageSettings, Permission::ManageThemes], 'site-operator', ['quick-actions' => $f, 'site-health' => $f]],
        'content editor' => [[Permission::AccessAdmin, Permission::ManagePages], 'content-editor', ['quick-actions' => $f, 'pages' => $f]],
        'admin access only' => [[Permission::AccessAdmin], 'user-manager', ['quick-actions' => $f]],
        // Mixed custom roles take the broadest preset that covers them, in its order.
        'uploader who edits pages' => [[Permission::AccessAdmin, Permission::UploadPhotos, Permission::CreateAlbums, Permission::ManagePages], 'administrator', ['quick-actions' => $f, 'your-albums' => $n, 'recent-uploads' => $n, 'pages' => $n]],
        'analyst who manages users' => [[Permission::AccessAdmin, Permission::ViewActivity, Permission::ManageUsers], 'administrator', ['quick-actions' => $f, 'members' => $n, 'library' => $f, 'activity-trends' => $f, 'recent-activity' => $w, 'popular-albums' => $n, 'activity-calendar' => $f]],
    ]);

    it('puts the trash where the preset plans it once it holds something', function () {
        $uploader = userWithRoleOrPermissions(Role::Uploader);
        $this->actingAs($uploader);
        Album::factory()->for($uploader, 'owner')->create()->delete();

        expect(array_keys(defaultsOf($uploader)))->toBe(['quick-actions', 'your-albums', 'recent-uploads', 'trash']);
    });

    it('saves order, hidden widgets and sizes from "Customize dashboard" and shows the dashboard that way', function () {
        Livewire::test(Dashboard::class)
            ->callAction('customize', data: ['widgets' => [
                ['key' => 'members', 'visible' => true, 'size' => DashboardLayout::FULL],
                ['key' => 'quick-actions', 'visible' => true, 'size' => DashboardLayout::NORMAL],
                ['key' => 'recent-activity', 'visible' => false, 'size' => DashboardLayout::WIDE],
            ]])
            ->assertHasNoActionErrors()
            ->assertNotified('Dashboard saved');

        $layout = layoutOf($this->admin);
        expect($layout[0])->toBe(['key' => 'members', 'hidden' => false, 'size' => DashboardLayout::FULL])
            ->and(collect($layout)->firstWhere('key', 'quick-actions')['size'])->toBe(DashboardLayout::NORMAL)
            ->and(collect($layout)->firstWhere('key', 'recent-activity')['hidden'])->toBeTrue()
            // Widgets missing from the saved list (e.g. new ones) follow the widget before them in the default order.
            ->and(array_slice(array_column($layout, 'key'), 0, 2))->toBe(['members', 'albums-attention']);

        $this->get('/admin')->assertOk()->assertDontSee('Full log')->assertSeeInOrder(['Members', 'Welcome']);
    });

    it('fills the editor with the current layout', function () {
        app(DashboardLayout::class)->save($this->admin, [['key' => 'pages', 'hidden' => true, 'size' => DashboardLayout::WIDE]]);

        Livewire::test(Dashboard::class)
            ->mountAction('customize')
            ->assertSchemaStateSet(fn (array $state) => expect(collect($state['widgets'])->firstWhere('key', 'pages'))->toBe(['key' => 'pages', 'visible' => false, 'size' => DashboardLayout::WIDE]));
    });

    it('applies the chosen width to the widget', function () {
        expect(Livewire::test(RecentActivity::class)->instance()->getColumnSpan())->toBe(['default' => 1, 'md' => 2])
            ->and(Livewire::test(RecentActivity::class, ['dashboardSize' => DashboardLayout::FULL])->instance()->getColumnSpan())->toBe('full')
            ->and(Livewire::test(RecentActivity::class, ['dashboardSize' => 'huge'])->instance()->getColumnSpan())->toBe(['default' => 1, 'md' => 2]);
    });

    it('drops saved widgets the person may no longer see, and shows them again where they were once allowed', function () {
        app(DashboardLayout::class)->save($this->admin, [
            ['key' => 'members', 'hidden' => false, 'size' => DashboardLayout::FULL],
            ['key' => 'quick-actions', 'hidden' => false, 'size' => DashboardLayout::FULL],
        ]);

        RoleModel::findOrCreate('editor')->syncPermissions([Permission::AccessAdmin->value, Permission::ManagePages->value]);
        $this->admin->syncRoles(['editor']);
        $this->admin->forgetCachedPermissions();

        expect(layoutKeys($this->admin))->toBe(['quick-actions', 'pages']);
        $this->get('/admin')->assertOk()->assertDontSee('Newest members');

        $this->admin->syncRoles([Role::Admin->value]);
        expect(layoutKeys($this->admin)[0])->toBe('members');
    });

    it('ignores widgets in a forged request that the person may not see', function () {
        $uploader = userWithRole(Role::Uploader);
        $this->actingAs($uploader);

        Livewire::test(Dashboard::class)->callAction('customize', data: ['widgets' => [
            ['key' => 'site-health', 'visible' => true, 'size' => DashboardLayout::FULL],
            ['key' => 'members', 'visible' => true, 'size' => DashboardLayout::FULL],
            ['key' => 'no-such-widget', 'visible' => true, 'size' => DashboardLayout::FULL],
            ['key' => 'your-albums', 'visible' => true, 'size' => DashboardLayout::WIDE],
        ]])->assertHasNoActionErrors();

        expect(array_column($uploader->fresh()->dashboard_layout['widgets'], 'key'))->toBe(['your-albums'])
            ->and(layoutKeys($uploader))->toBe(['quick-actions', 'your-albums', 'recent-uploads']);

        // Saved values are checked on the server too, not only by the form.
        app(DashboardLayout::class)->save($uploader, [['key' => 'your-albums', 'hidden' => 'no', 'size' => 'gigantic'], ['key' => 'your-albums', 'hidden' => true]]);
        // An invalid size falls back to the uploader preset's width.
        expect($uploader->fresh()->dashboard_layout['widgets'])->toBe([['key' => 'your-albums', 'hidden' => false, 'size' => DashboardLayout::WIDE]]);
        $this->get('/admin')->assertOk()->assertDontSee('Site health')->assertDontSee('Newest members');
    });

    it('resets to the default layout', function () {
        app(DashboardLayout::class)->save($this->admin, [['key' => 'pages', 'hidden' => true, 'size' => DashboardLayout::FULL]]);

        Livewire::test(Dashboard::class)
            ->mountAction('customize')
            ->callAction('resetDashboard')
            ->assertNotified('Dashboard reset');

        expect($this->admin->fresh()->dashboard_layout)->toBeNull()
            ->and(collect(layoutOf($this->admin))->firstWhere('key', 'pages'))->toBe(['key' => 'pages', 'hidden' => false, 'size' => DashboardLayout::NORMAL]);
    });

    it('says how to get panels back when all are hidden', function () {
        app(DashboardLayout::class)->save($this->admin, array_map(fn (string $key) => ['key' => $key, 'hidden' => true], layoutKeys($this->admin)));

        $this->get('/admin')->assertOk()->assertSee('All panels are hidden.')->assertDontSee('Welcome,');
    });

    it('does not log layout changes', function () {
        $before = ActivityEvent::count();

        Livewire::test(Dashboard::class)->callAction('customize', data: ['widgets' => [['key' => 'pages', 'visible' => false, 'size' => DashboardLayout::NORMAL]]]);
        $this->admin->forceFill(['dashboard_layout' => null])->save();

        expect(ActivityEvent::count())->toBe($before);
    });

    it('ignores a stored layout it does not understand', function () {
        $this->admin->forceFill(['dashboard_layout' => ['v' => 99, 'widgets' => [['key' => 'pages', 'hidden' => true]]]])->saveQuietly();

        expect(collect(layoutOf($this->admin))->firstWhere('key', 'pages')['hidden'])->toBeFalse();
    });
});
