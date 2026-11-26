<?php

use App\Enums\Permission;
use App\Enums\Role as BuiltInRole;
use App\Filament\Actions\ActionIcons;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RelationManagers\HoldersRelationManager;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Tables\Columns\UserStackColumn;
use App\Models\ActivityEvent;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Enums\FiltersLayout;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionModel;

beforeEach(fn () => actingAsRole(BuiltInRole::Admin));

it('assigns every permission to exactly one group', function () {
    $grouped = collect(Permission::grouped())->flatten();

    expect($grouped->map->value->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all())
        ->and($grouped->duplicates())->toBeEmpty();
});

it('lists the first holders of each role with their pictures, links them, and filters roles by holder', function () {
    $role = Role::findOrCreate('curator');
    $holders = User::factory()->count(UserStackColumn::SHOWN + 3)
        ->sequence(fn ($sequence) => ['name' => 'Holder '.chr(65 + $sequence->index)])
        ->create()
        ->each->assignRole($role);

    $page = Livewire::test(ListRoles::class);
    $table = $page->instance()->getTable();
    $record = $page->instance()->getTableRecords()->firstWhere('name', 'curator');

    expect($record->users)->toHaveCount(UserStackColumn::SHOWN)
        ->and($record->users_count)->toBe(UserStackColumn::SHOWN + 3)
        ->and($page->html())->toContain('alt="Holder A"', '+3', UserResource::getUrl('edit', ['record' => $holders->first()]))->not->toContain('alt="Holder I"')
        ->and($table->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
        ->and($table->hasDeferredFilters())->toBeFalse()
        ->and($table->getRecordUrl($record))->toBe(RoleResource::getUrl('edit', ['record' => $role]));

    $page->assertActionDoesNotExist(TestAction::make('edit')->table($role))
        ->filterTable('users', $holders->first()->id)
        ->assertCanSeeTableRecords([$role])
        ->assertCanNotSeeTableRecords([Role::findByName(BuiltInRole::Admin->value)]);
});

it('shows a role\'s permissions in their groups and saves each group on its own', function () {
    $role = Role::findOrCreate('curator');
    $role->givePermissionTo([Permission::CreateAlbums->value, Permission::ManagePages->value]);

    Livewire::test(EditRole::class, ['record' => $role->id])
        ->assertSchemaStateSet([
            'permission_groups.albums_photos' => [permissionId(Permission::CreateAlbums)],
            'permission_groups.site_appearance' => [permissionId(Permission::ManagePages)],
            'permission_groups.admin_area' => [],
        ])
        ->fillForm([
            'permission_groups.albums_photos' => [permissionId(Permission::UploadPhotos)],
            'permission_groups.admin_area' => [permissionId(Permission::ViewActivity)],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe([Permission::ManagePages->value, Permission::UploadPhotos->value, Permission::ViewActivity->value]);
});

it('creates a role with grouped permissions and opens its page', function () {
    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'helper',
            'permission_groups' => ['admin_area' => [permissionId(Permission::AccessAdmin)]],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(RoleResource::getUrl('edit', ['record' => Role::findByName('helper')]));

    expect(Role::findByName('helper')->permissions->pluck('name')->all())->toBe([Permission::AccessAdmin->value]);
});

it('lists permissions added outside the enum under "Other"', function () {
    $extra = PermissionModel::findOrCreate('use the guest book');
    $role = tap(Role::findOrCreate('guest-host'))->givePermissionTo($extra);

    Livewire::test(EditRole::class, ['record' => $role->id])
        ->assertSchemaStateSet(['permission_groups.other' => [$extra->id]]);
});

it('offers deleting a custom role with an icon, but keeps built-in roles from being renamed or deleted', function () {
    $custom = Role::findOrCreate('curators');
    $builtIn = Role::findByName(BuiltInRole::Moderator->value);

    Livewire::test(EditRole::class, ['record' => $custom->getRouteKey()])
        ->assertActionExists('delete')
        ->assertActionHasIcon('delete', ActionIcons::DELETE)
        ->assertActionHasColor('delete', 'danger');
    Livewire::test(EditRole::class, ['record' => $builtIn->id])
        ->assertFormFieldDisabled('name')
        ->assertActionHidden('delete');
});

it('gives and takes away the role from the role page, and logs it', function () {
    $role = Role::findOrCreate('curator');
    [$anna, $ben] = User::factory()->count(2)->sequence(['name' => 'Anna'], ['name' => 'Ben'])->create();

    $holders = Livewire::test(HoldersRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])
        ->callAction(TestAction::make('attach')->table(), ['recordId' => [$anna->id, $ben->id]])
        ->assertHasNoActionErrors()
        ->assertSeeHtml(UserResource::getUrl('edit', ['record' => $anna]));

    expect($anna->fresh()->hasRole('curator'))->toBeTrue()
        ->and($ben->fresh()->hasRole('curator'))->toBeTrue()
        ->and(ActivityEvent::query()->where('subject_type', Role::class)->where('subject_id', $role->id)->latest('id')->first()->details())
        ->toContain('Users: +Anna, +Ben');

    $holders->callAction(TestAction::make('detach')->table($ben));
    expect($ben->fresh()->hasRole('curator'))->toBeFalse();
});
