<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Role as RoleModel;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * A new user with a built-in role.
 *
 * @param  array<string, mixed>  $attributes  factory attributes to override
 */
function userWithRole(Role $role = Role::Admin, array $attributes = []): User
{
    return tap(User::factory()->create($attributes))->assignRole($role->value);
}

/**
 * A new user with a built-in role, signed in for the rest of the test.
 *
 * @param  array<string, mixed>  $attributes  factory attributes to override
 */
function actingAsRole(Role $role = Role::Admin, array $attributes = []): User
{
    $user = userWithRole($role, $attributes);
    test()->actingAs($user);

    return $user;
}

/**
 * A new user whose own role $roleName holds exactly $permissions.
 *
 * @param  list<Permission>  $permissions
 */
function userWithPermissions(string $roleName, array $permissions): User
{
    RoleModel::findOrCreate($roleName)->syncPermissions(array_map(fn (Permission $permission) => $permission->value, $permissions));

    return tap(User::factory()->create())->assignRole($roleName);
}

/**
 * A user of one of the audited types: a built-in role, or a custom role
 * with exactly these permissions.
 *
 * @param  Role|list<Permission>  $type
 */
function userWithRoleOrPermissions(Role|array $type): User
{
    if ($type instanceof Role) {
        return userWithRole($type);
    }

    return userWithPermissions('custom-'.md5(serialize($type)), $type);
}

/** Someone with "Manage users" but not every permission. */
function userManager(): User
{
    return userWithPermissions('user manager', [Permission::AccessAdmin, Permission::ManageUsers]);
}

/** Someone who may create albums in the admin but not see every album. */
function albumCreator(): User
{
    return userWithPermissions('album creator', [Permission::AccessAdmin, Permission::CreateAlbums]);
}

/** Staff with admin access but without "view any album": sees only what their albums allow. */
function uploadOnlyStaff(): User
{
    return userWithPermissions('helper', [Permission::AccessAdmin, Permission::UploadPhotos]);
}

/** Someone who may edit pages but is not staff (cannot see every album). */
function pageEditor(): User
{
    return userWithPermissions('page editor', [Permission::AccessAdmin, Permission::ManagePages]);
}

/** Someone who can open the admin but has no rights on other people's albums. */
function adminAccessUser(): User
{
    return tap(User::factory()->create())->givePermissionTo(Permission::AccessAdmin->value);
}

/** The id of the Spatie permission row for $permission. */
function permissionId(Permission $permission): int
{
    return PermissionModel::findByName($permission->value)->id;
}

/** The permission names the database currently grants to $role, sorted. @return list<string> */
function roleGrants(Role $role): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return RoleModel::findByName($role->value)->permissions()->pluck('name')->sort()->values()->all();
}

/** The permission names the seeder's matrix grants to $role, sorted. @return list<string> */
function standardGrants(Role $role): array
{
    return collect(RolesAndPermissionsSeeder::matrix()[$role->value])->map->value->sort()->values()->all();
}
