<?php

use App\Enums\Permission;
use App\Enums\Role as BuiltInRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/** Someone with "Manage users" but not every permission. */
function userManager(): User
{
    Role::findOrCreate('user manager')->givePermissionTo([
        Permission::AccessAdmin->value,
        Permission::ManageUsers->value,
    ]);

    return tap(User::factory()->create())->assignRole('user manager');
}

function userWithRole(string $role): User
{
    return tap(User::factory()->create())->assignRole($role);
}

it('lets a user manager edit and delete only people whose roles they could give', function (?string $role, bool $allowed) {
    $manager = userManager();
    $target = $role ? userWithRole($role) : User::factory()->create();

    expect($manager->can('update', $target))->toBe($allowed)
        ->and($manager->can('delete', $target))->toBe($allowed);
})->with([
    'no role' => [null, true],
    'user (fewer permissions)' => [BuiltInRole::User->value, true],
    'same custom role' => ['user manager', true],
    'uploader (permissions they lack)' => [BuiltInRole::Uploader->value, false],
    'admin' => [BuiltInRole::Admin->value, false],
    'super admin' => [BuiltInRole::SuperAdmin->value, false],
]);

it('refuses to open the edit page of a super admin for a user manager', function () {
    $this->actingAs(userManager());

    Livewire::test(EditUser::class, ['record' => userWithRole(BuiltInRole::SuperAdmin->value)->id])
        ->assertForbidden();
});

it('refuses roles above the user manager\'s own permissions', function (string $role) {
    $this->actingAs(userManager());

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New person',
            'email' => 'new@example.com',
            'password' => 'a-long-enough-password',
            'roles' => [Role::findByName($role)->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['roles.0' => 'The selected roles is invalid.']);

    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();
})->with([
    'super admin' => [BuiltInRole::SuperAdmin->value],
    'admin' => [BuiltInRole::Admin->value],
    'own role plus more' => [BuiltInRole::Moderator->value],
]);

it('lets a user manager give roles within their own permissions, themselves included', function () {
    $manager = userManager();
    $this->actingAs($manager);

    Livewire::test(EditUser::class, ['record' => $manager->id])
        ->fillForm(['roles' => [Role::findByName('user manager')->id, Role::findByName(BuiltInRole::User->value)->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($manager->fresh()->getRoleNames()->sort()->values()->all())->toBe(['user', 'user manager']);
});

it('lets a super admin give the super admin role', function () {
    $this->actingAs(userWithRole(BuiltInRole::SuperAdmin->value));
    $target = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [Role::findByName(BuiltInRole::SuperAdmin->value)->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole(BuiltInRole::SuperAdmin->value))->toBeTrue();
});

it('skips people the user manager may not manage when deleting in bulk', function () {
    $this->actingAs(userManager());
    $member = userWithRole(BuiltInRole::User->value);
    $superAdmin = userWithRole(BuiltInRole::SuperAdmin->value);

    Livewire::test(ListUsers::class)
        ->selectTableRecords([$member->id, $superAdmin->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    expect(User::find($member->id))->toBeNull()
        ->and(User::find($superAdmin->id))->not->toBeNull();
});
