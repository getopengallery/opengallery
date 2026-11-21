<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\LibraryOverview;
use App\Models\Role as RoleModel;
use App\Models\User;

it('links each figure to its list', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    expect(collect((new LibraryOverview)->getFigures())->pluck('url')->all())->toBe([
        AlbumResource::getUrl(),
        PhotoResource::getUrl(),
        UserResource::getUrl(),
        GroupResource::getUrl(),
        null,
    ]);
});

it('does not link figures to lists the person may not open', function () {
    RoleModel::findOrCreate('watcher')->syncPermissions([Permission::AccessAdmin->value, Permission::ViewActivity->value]);
    $this->actingAs(tap(User::factory()->create())->assignRole('watcher'));

    // Albums and media are open to every admin user (scoped to what they may see); users and groups are not.
    expect(collect((new LibraryOverview)->getFigures())->pluck('url')->all())->toBe([
        AlbumResource::getUrl(),
        PhotoResource::getUrl(),
        null,
        null,
        null,
    ]);
});
