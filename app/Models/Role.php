<?php

namespace App\Models;

use App\Enums\Role as BuiltInRole;
use App\Models\Pivots\RolePermission;
use App\Models\Pivots\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie role with custom pivots, so permission and user changes reach the activity log.
 * Registered as `permission.models.role`.
 */
class Role extends SpatieRole
{
    public function permissions(): BelongsToMany
    {
        return parent::permissions()->using(RolePermission::class);
    }

    public function users(): BelongsToMany
    {
        return parent::users()->using(UserRole::class);
    }

    /**
     * Roles the user may give to or take from others: every role for super
     * admins, otherwise only roles whose permissions they hold themselves.
     * Super-admin is never among them for anyone else.
     */
    public function scopeAssignableBy(Builder $query, User $user): void
    {
        if ($user->hasRole(BuiltInRole::SuperAdmin->value)) {
            return;
        }

        $held = $user->getAllPermissions()->modelKeys();

        $query->where('roles.name', '!=', BuiltInRole::SuperAdmin->value)
            ->whereDoesntHave('permissions', fn (Builder $query) => $query->whereKeyNot($held));
    }
}
