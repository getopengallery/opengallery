<?php

namespace App\Filament\Resources\Roles\RelationManagers;

use App\Filament\RelationManagers\UsersRelationManager;
use Illuminate\Database\Eloquent\Model;

/** The users who have a role, on the role's edit page. */
class HoldersRelationManager extends UsersRelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Users with this role');
    }

    protected function removeHeading(): string
    {
        return __('Remove role');
    }

    protected function emptyHeading(): string
    {
        return __('Nobody has this role yet');
    }
}
