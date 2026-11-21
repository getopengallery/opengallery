<?php

namespace Database\Seeders;

use App\Enums\Permission as P;
use App\Enums\Role as R;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission and the built-in roles. Runs on every
 * `gallery:install`, which is also the upgrade step, so it never undoes what
 * administrators changed in Users & access → Roles:
 *
 *  - a built-in role created now gets its full standard set;
 *  - an existing role only gets permissions the seeder has not given it
 *    before (new in this release), and nothing is ever taken away.
 *
 * What the seeder has given each role is remembered in the setting
 * {@see self::SEEDED_SETTING}. Installations from before that setting
 * existed are treated as having received {@see self::untrackedMatrix()},
 * except for permissions created in this run.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const SEEDED_SETTING = 'roles.seeded_permissions';

    /**
     * The standard permissions of each built-in role.
     *
     * @return array<string, list<P>>
     */
    public static function matrix(): array
    {
        return [
            // Super admin bypasses every check via Gate::before (AppServiceProvider).
            R::SuperAdmin->value => [],
            R::Admin->value => P::cases(),
            R::Moderator->value => [
                P::AccessAdmin, P::ViewActivity, P::ViewAnyAlbum, P::EditAnyAlbum,
                P::CreateAlbums, P::UploadPhotos, P::InviteCollaborators,
            ],
            R::Uploader->value => [P::AccessAdmin, P::CreateAlbums, P::UploadPhotos, P::InviteCollaborators],
            R::User->value => [],
        ];
    }

    /**
     * The standard sets as they were before the seeder kept track of what it
     * gave (the baseline for older installations). Only change it to drop
     * permissions that no longer exist.
     *
     * @return array<string, list<P>>
     */
    private static function untrackedMatrix(): array
    {
        return [
            ...self::matrix(),
            R::Uploader->value => [P::CreateAlbums, P::UploadPhotos],
        ];
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $created = [];
        foreach (P::cases() as $permission) {
            if (Permission::findOrCreate($permission->value)->wasRecentlyCreated) {
                $created[] = $permission->value;
            }
        }

        /** @var array<string, list<string>> $seeded */
        $seeded = Setting::query()->find(self::SEEDED_SETTING)?->value ?? [];

        foreach (self::matrix() as $name => $permissions) {
            $standard = array_map(fn (P $permission) => $permission->value, $permissions);
            $role = Role::findOrCreate($name);

            $givenBefore = match (true) {
                $role->wasRecentlyCreated => [],
                isset($seeded[$name]) => $seeded[$name],
                default => array_values(array_diff(
                    array_map(fn (P $permission) => $permission->value, self::untrackedMatrix()[$name] ?? []),
                    $created,
                )),
            };

            $missing = array_values(array_diff($standard, $givenBefore));
            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }

            $seeded[$name] = array_values(array_unique([...$givenBefore, ...$standard]));
        }

        Setting::put(self::SEEDED_SETTING, $seeded);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
