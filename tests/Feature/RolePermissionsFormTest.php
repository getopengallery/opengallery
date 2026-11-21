<?php

use App\Enums\Permission;
use App\Enums\Role as BuiltInRole;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RelationManagers\HoldersRelationManager;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Tables\Columns\UserStackColumn;
use App\Models\ActivityEvent;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionModel;

function permissionId(Permission $permission): int
{
    return PermissionModel::findByName($permission->value)->id;
}

beforeEach(fn () => $this->actingAs(tap(User::factory()->create())->assignRole(BuiltInRole::Admin->value)));

it('assigns every permission to exactly one group', function () {
    $grouped = collect(Permission::grouped())->flatten();

    expect($grouped->map->value->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all())
        ->and($grouped->duplicates())->toBeEmpty();
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

it('keeps built-in roles from being renamed or deleted', function () {
    $role = Role::findByName(BuiltInRole::Moderator->value);

    Livewire::test(EditRole::class, ['record' => $role->id])
        ->assertFormFieldDisabled('name')
        ->assertActionHidden('delete');
});

it('shows the first users holding a role with their pictures and counts the rest', function () {
    $role = Role::findOrCreate('curator');
    User::factory()->count(UserStackColumn::SHOWN + 3)
        ->sequence(fn ($sequence) => ['name' => 'Holder '.chr(65 + $sequence->index)])
        ->create()
        ->each->assignRole($role);

    $page = Livewire::test(ListRoles::class);
    $record = $page->instance()->getTableRecords()->firstWhere('name', 'curator');

    expect($record->users)->toHaveCount(UserStackColumn::SHOWN)
        ->and($record->users_count)->toBe(UserStackColumn::SHOWN + 3)
        ->and($page->html())->toContain('alt="Holder A"', '+3')->not->toContain('alt="Holder I"');
});

it('gives and takes away the role from the role page, and logs it', function () {
    $role = Role::findOrCreate('curator');
    [$anna, $ben] = User::factory()->count(2)->sequence(['name' => 'Anna'], ['name' => 'Ben'])->create();

    $holders = Livewire::test(HoldersRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])
        ->callAction(TestAction::make('attach')->table(), ['recordId' => [$anna->id, $ben->id]])
        ->assertHasNoActionErrors();

    expect($anna->fresh()->hasRole('curator'))->toBeTrue()
        ->and($ben->fresh()->hasRole('curator'))->toBeTrue()
        ->and(ActivityEvent::query()->where('subject_type', Role::class)->where('subject_id', $role->id)->latest('id')->first()->details())
        ->toContain('Users: +Anna, +Ben');

    $holders->callAction(TestAction::make('detach')->table($ben));
    expect($ben->fresh()->hasRole('curator'))->toBeFalse();
});
