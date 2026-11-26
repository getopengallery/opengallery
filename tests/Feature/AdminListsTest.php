<?php

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Extensions\PluginManager;
use App\Extensions\ThemeManager;
use App\Filament\Pages\Plugins;
use App\Filament\Pages\Themes;
use App\Filament\Pages\ThemeSettings;
use App\Filament\Resources\ActivityEvents\Pages\ManageActivityEvents;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Livewire\MediaPicker;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Page;
use App\Models\Role as RoleModel;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Enums\FiltersLayout;
use Livewire\Livewire;
use Plugins\IdentityProviders\Filament\Settings as IdentityProviderSettings;

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::SuperAdmin->value));
});

it('filters every admin list the same way: a dropdown that applies at once', function (string $component, array $params = []) {
    $table = Livewire::test($component, $params)->instance()->getTable();

    expect($table->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
        ->and($table->hasDeferredFilters())->toBeFalse();
})->with([
    'albums' => [ListAlbums::class],
    'media' => [ListPhotos::class],
    'media picker' => [MediaPicker::class, ['key' => 'test']],
    'users' => [ListUsers::class],
    'groups' => [ListGroups::class],
    'roles' => [ListRoles::class],
    'pages' => [ListPages::class],
    'activity log' => [ManageActivityEvents::class],
]);

it('keeps the same filters when the media library switches between grid and list', function () {
    $page = Livewire::test(ListPhotos::class);
    $grid = array_keys($page->instance()->getTable()->getFilters());

    $page->call('setLayout', ListPhotos::LIST);

    expect(array_keys($page->instance()->getTable()->getFilters()))->toBe($grid);
});

it('filters users by role, group and verified address', function () {
    $team = Group::factory()->create();
    $verifiedUploader = tap(User::factory()->create())->assignRole(Role::Uploader->value);
    $unverifiedMember = tap(User::factory()->unverified()->create())->assignRole(Role::User->value);
    $team->users()->attach($unverifiedMember);

    Livewire::test(ListUsers::class)
        ->filterTable('roles', [RoleModel::findByName(Role::Uploader->value)->id])
        ->assertCanSeeTableRecords([$verifiedUploader])
        ->assertCanNotSeeTableRecords([$unverifiedMember])
        ->resetTableFilters()
        ->filterTable('groups', [$team->id])
        ->assertCanSeeTableRecords([$unverifiedMember])
        ->assertCanNotSeeTableRecords([$verifiedUploader])
        ->resetTableFilters()
        ->filterTable('email_verified_at', false)
        ->assertCanSeeTableRecords([$unverifiedMember])
        ->assertCanNotSeeTableRecords([$verifiedUploader]);
});

it('filters groups by member and roles by holder', function () {
    $member = User::factory()->create();
    [$joined, $other] = Group::factory()->count(2)->create();
    $joined->users()->attach($member);
    $member->assignRole(Role::Moderator->value);

    Livewire::test(ListGroups::class)
        ->filterTable('users', $member->id)
        ->assertCanSeeTableRecords([$joined])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ListRoles::class)
        ->filterTable('users', $member->id)
        ->assertCanSeeTableRecords([RoleModel::findByName(Role::Moderator->value)])
        ->assertCanNotSeeTableRecords([RoleModel::findByName(Role::Admin->value)]);
});

it('filters pages into published pages and drafts', function () {
    $published = Page::factory()->create(['is_published' => true]);
    $draft = Page::factory()->create(['is_published' => false]);

    Livewire::test(ListPages::class)
        ->filterTable('is_published', false)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$published]);
});

it('turns plugins on and off with the switch in their row, then reloads the list', function () {
    Livewire::test(Plugins::class)
        ->call('updateTableColumnState', 'active', 'example', true)
        ->assertRedirect(Plugins::getUrl());
    expect(app(PluginManager::class)->isEnabled('example'))->toBeTrue();

    Livewire::test(Plugins::class)->call('updateTableColumnState', 'active', 'example', false);
    expect(app(PluginManager::class)->isEnabled('example'))->toBeFalse();
});

it('opens a plugin\'s settings from its row while it is on', function () {
    $table = Livewire::test(Plugins::class)->instance()->getTable();

    expect($table->getRecordUrl($table->getLivewire()->getTableRecord('identity-providers')))->toBe(IdentityProviderSettings::getUrl())
        ->and($table->getRecordUrl($table->getLivewire()->getTableRecord('example')))->toBeNull();
});

it('searches plugins by name and description', function () {
    Livewire::test(Plugins::class)
        ->searchTable('example')
        ->assertSee('Example Plugin')
        ->assertDontSee('Identity providers');
});

it('locks the switch of a plugin enabled in the server configuration', function () {
    Livewire::test(Plugins::class)
        ->assertSee('Enabled in the server configuration')
        ->call('updateTableColumnState', 'active', 'identity-providers', false);
    expect(app(PluginManager::class)->isEnabled('identity-providers'))->toBeTrue();

    Livewire::test(Plugins::class)->call('toggle', 'identity-providers')->assertStatus(422);
});

it('switches to another theme with the Activate button in its row and opens a theme\'s settings from its row', function () {
    $page = Livewire::test(Themes::class);
    $table = $page->instance()->getTable();
    expect($table->getRecordUrl($page->instance()->getTableRecord('default')))->toBe(ThemeSettings::getUrl(['theme' => 'default']));

    // The active theme is marked instead of offering to activate it again.
    $page->assertActionHidden(TestAction::make('activate')->table('default'))
        ->assertActionVisible(TestAction::make('active')->table('default'))
        ->assertActionDisabled(TestAction::make('active')->table('default'))
        ->assertActionVisible(TestAction::make('activate')->table('scrapbook'))
        ->assertActionHidden(TestAction::make('active')->table('scrapbook'));

    // The theme in use is listed first.
    expect(array_key_first($page->instance()->getTableRecords()->all()))->toBe('default');

    $page->mountAction(TestAction::make('activate')->table('scrapbook'))
        ->assertMountedActionModalSee(['Activate Scrapbook?', 'Visitors see the new look right away.']);
    expect(app(ThemeManager::class)->active()->slug)->toBe('default');

    $page->callMountedAction()->assertRedirect(Themes::getUrl());
    expect(app(ThemeManager::class)->active()->slug)->toBe('scrapbook');
});

it('says what an empty list is for and offers to fill it', function () {
    Livewire::test(ListGroups::class)
        ->assertSee('No groups yet')
        ->assertSee('Groups let you share restricted albums with several people at once.')
        ->assertActionVisible(TestAction::make('create')->table());
});

it('says nothing matches instead while a search hides every row', function () {
    Group::factory()->create(['name' => 'Family']);

    Livewire::test(ListGroups::class)
        ->searchTable('Nobody here')
        ->assertSee('Nothing matches')
        ->assertDontSee('No groups yet')
        ->assertActionHidden(TestAction::make('create')->table());
});

it('shows the label of every icon-only button as its tooltip', function () {
    expect(Action::make('edit')->label('Edit')->iconButton()->getTooltip())->toBe('Edit')
        ->and(Action::make('edit')->label('Edit')->getTooltip())->toBeNull()
        ->and(Action::make('edit')->label('Edit')->tooltip('Change it')->iconButton()->getTooltip())->toBe('Change it');
});

it('links the people, roles and groups named in admin lists to their pages', function () {
    $owner = User::factory()->create();
    $album = Album::factory()->create(['owner_id' => $owner->id]);
    $group = Group::factory()->create();
    $role = RoleModel::findByName(Role::Uploader->value);
    $owner->assignRole($role);
    $group->users()->attach($owner);
    $login = ActivityEvent::factory()->create(['user_id' => $owner->id]);
    $ownerUrl = UserResource::getUrl('edit', ['record' => $owner]);

    Livewire::test(ListAlbums::class)->assertCanSeeTableRecords([$album])->assertSeeHtml($ownerUrl);
    Livewire::test(ListUsers::class)
        ->assertSeeHtml(RoleResource::getUrl('edit', ['record' => $role]))
        ->assertSeeHtml(GroupResource::getUrl('edit', ['record' => $group]));
    Livewire::test(ListGroups::class)->assertSeeHtml($ownerUrl);
    Livewire::test(ListRoles::class)->assertSeeHtml($ownerUrl);
    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditGroup::class])->assertSeeHtml($ownerUrl);
    Livewire::test(ManageActivityEvents::class)->assertCanSeeTableRecords([$login])->assertSeeHtml($ownerUrl);
});

it('names people, roles and groups as plain text to those who may not open them', function () {
    $member = tap(User::factory()->create())->assignRole(Role::Uploader->value);
    $group = Group::factory()->create();
    $group->users()->attach($member);
    $this->actingAs(tap(User::factory()->create())->givePermissionTo(Permission::AccessAdmin->value, Permission::ManageUsers->value));

    Livewire::test(ListUsers::class)
        ->assertSee($group->name)
        ->assertDontSeeHtml(RoleResource::getUrl('edit', ['record' => RoleModel::findByName(Role::Uploader->value)]))
        ->assertDontSeeHtml(GroupResource::getUrl('edit', ['record' => $group]));
});

it('keeps the account behind a failed login unlinked in the activity log', function () {
    $target = User::factory()->create();
    $failed = ActivityEvent::factory()->create(['type' => ActivityType::LoginFailed, 'user_id' => $target->id]);

    Livewire::test(ManageActivityEvents::class)
        // Only the failed login: creating the account is logged too, and links it as the subject.
        ->filterTable('type', [ActivityType::LoginFailed->value])
        ->assertCanSeeTableRecords([$failed])
        ->assertDontSeeHtml(UserResource::getUrl('edit', ['record' => $target]));
});

it('shows badges only for cells that can hold several entries, plus album visibility', function (string $component, array $badged, array $plain) {
    $columns = Livewire::test($component)->instance()->getTable()->getColumns();

    foreach ($badged as $name) {
        expect($columns[$name]->isBadge())->toBeTrue("{$name} should be a badge");
    }

    foreach ($plain as $name) {
        expect($columns[$name]->isBadge())->toBeFalse("{$name} should be plain text");
    }
})->with([
    'albums' => [ListAlbums::class, ['visibility'], ['title', 'owner.name', 'collaborator_role']],
    'users' => [ListUsers::class, ['roles.name', 'groups.name'], ['name', 'email']],
    'activity log' => [ManageActivityEvents::class, [], ['user.name', 'what', 'details']],
]);
