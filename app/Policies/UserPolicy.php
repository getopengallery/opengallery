<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * "Manage users" never lifts anyone above the editor: people may only edit or
 * delete accounts whose roles they could give themselves (User::canManage()).
 * Super admins pass everything through Gate::before.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::ManageUsers->value) && $user->canManage($model);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can(Permission::ManageUsers->value) && $user->canManage($model);
    }

    /** Bulk deletion is offered; each selected user is checked with delete(). */
    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }
}
