<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\RecentActivity;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Livewire\Livewire;

/** @return list<array{key: string, hidden: bool, size: string}> the signed-in user's layout without widget classes */
function layoutOf(User $user): array
{
    return array_map(fn (array $entry) => ['key' => $entry['key'], 'hidden' => $entry['hidden'], 'size' => $entry['size']], app(DashboardLayout::class)->entries($user->fresh()));
}

/** @return list<string> */
function layoutKeys(User $user): array
{
    return array_column(layoutOf($user), 'key');
}

beforeEach(function () {
    $this->admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $this->actingAs($this->admin);
});

/**
 * A user with a built-in role, or a custom role with exactly these permissions.
 *
 * @param  Role|list<Permission>  $type
 */
function layoutUser(Role|array $type): User
{
    $user = User::factory()->create();

    if ($type instanceof Role) {
        return tap($user)->assignRole($type->value);
    }

    $role = RoleModel::findOrCreate('layout-'.md5(serialize($type)));
    $role->syncPermissions(array_map(fn (Permission $permission) => $permission->value, $type));

    return tap($user)->assignRole($role);
}

/** @return array<string, string> the signed-in user's default arrangement, key => size */
function defaultsOf(User $user): array
{
    test()->actingAs($user);

    return collect(layoutOf($user))->mapWithKeys(fn (array $entry) => [$entry['key'] => $entry['size']])->all();
}

$n = DashboardLayout::NORMAL;
$w = DashboardLayout::WIDE;
$f = DashboardLayout::FULL;

dataset('default layouts', [
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

it('starts each kind of user with the preset that fits what they may see', function (Role|array $type, string $preset, array $expected) {
    $user = layoutUser($type);
    $this->actingAs($user);

    expect(DashboardLayout::presetFor(app(DashboardLayout::class)->available()))->toBe($preset)
        ->and(defaultsOf($user))->toBe($expected)
        ->and($user->fresh()->dashboard_layout)->toBeNull();
})->with('default layouts');

it('puts the trash where the preset plans it once it holds something', function () {
    $uploader = layoutUser(Role::Uploader);
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
    $uploader = tap(User::factory()->create())->assignRole(Role::Uploader->value);
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
