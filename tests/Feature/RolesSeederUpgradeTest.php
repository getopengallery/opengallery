<?php

use App\Enums\Permission;
use App\Enums\Role as BuiltInRole;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;

it('gives the built-in roles their standard permissions on a fresh install', function (BuiltInRole $role) {
    expect(roleGrants($role))->toBe(standardGrants($role));
})->with(BuiltInRole::cases());

it('keeps permissions administrators added or removed when gallery:install runs again', function () {
    tap(User::factory()->create())->assignRole(BuiltInRole::SuperAdmin->value);
    Role::findByName(BuiltInRole::Moderator->value)->revokePermissionTo(Permission::EditAnyAlbum->value);
    Role::findByName(BuiltInRole::User->value)->givePermissionTo(Permission::UploadPhotos->value);

    $this->artisan('gallery:install')->assertSuccessful();

    expect(roleGrants(BuiltInRole::Moderator))->not->toContain(Permission::EditAnyAlbum->value)
        ->and(roleGrants(BuiltInRole::User))->toBe([Permission::UploadPhotos->value]);
});

it('adds a permission a new release gives a role, without undoing other changes', function () {
    $moderator = Role::findByName(BuiltInRole::Moderator->value);
    $moderator->revokePermissionTo([Permission::ViewActivity->value, Permission::EditAnyAlbum->value]);
    // As if the previous release had not given moderators "view activity" yet.
    $seeded = Setting::query()->find(RolesAndPermissionsSeeder::SEEDED_SETTING)->value;
    $seeded[BuiltInRole::Moderator->value] = array_values(array_diff($seeded[BuiltInRole::Moderator->value], [Permission::ViewActivity->value]));
    Setting::put(RolesAndPermissionsSeeder::SEEDED_SETTING, $seeded);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(roleGrants(BuiltInRole::Moderator))->toContain(Permission::ViewActivity->value)
        ->not->toContain(Permission::EditAnyAlbum->value);
});

it('upgrades an installation from before the seeder kept track, giving only new permissions', function () {
    Setting::query()->whereKey(RolesAndPermissionsSeeder::SEEDED_SETTING)->delete();
    Role::findByName(BuiltInRole::Moderator->value)->revokePermissionTo(Permission::EditAnyAlbum->value);
    // As if "view activity" were new in this release.
    PermissionModel::findByName(Permission::ViewActivity->value)->delete();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(roleGrants(BuiltInRole::Moderator))->toContain(Permission::ViewActivity->value)
        ->not->toContain(Permission::EditAnyAlbum->value)
        ->and(roleGrants(BuiltInRole::Admin))->toBe(standardGrants(BuiltInRole::Admin));
});

it('recreates a deleted built-in role with its standard permissions', function () {
    Role::findByName(BuiltInRole::Uploader->value)->delete();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(roleGrants(BuiltInRole::Uploader))->toBe(standardGrants(BuiltInRole::Uploader));
});

it('opens the admin area to uploaders on installations from before the seeder kept track', function () {
    Setting::query()->whereKey(RolesAndPermissionsSeeder::SEEDED_SETTING)->delete();
    $uploader = Role::findByName(BuiltInRole::Uploader->value);
    $uploader->revokePermissionTo(Permission::AccessAdmin->value);
    Role::findByName(BuiltInRole::Moderator->value)->revokePermissionTo(Permission::EditAnyAlbum->value);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(roleGrants(BuiltInRole::Uploader))->toBe(standardGrants(BuiltInRole::Uploader))
        ->and(roleGrants(BuiltInRole::Moderator))->not->toContain(Permission::EditAnyAlbum->value);
});

it('removes the former comment permissions from existing installations', function () {
    $legacy = collect(['comment', 'moderate comments'])->map(fn (string $name) => PermissionModel::findOrCreate($name));
    Role::findByName(BuiltInRole::User->value)->givePermissionTo($legacy);

    (require database_path('migrations/2026_11_14_224213_remove_unused_tags_favorites_and_comment_permissions.php'))->up();

    expect(PermissionModel::whereIn('name', ['comment', 'moderate comments'])->exists())->toBeFalse()
        ->and(roleGrants(BuiltInRole::User))->toBe([]);
});
