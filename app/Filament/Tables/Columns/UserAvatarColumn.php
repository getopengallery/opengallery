<?php

namespace App\Filament\Tables\Columns;

use App\Models\User;
use Filament\Tables\Columns\ImageColumn;

/**
 * A person's profile picture (or initials, see InitialsAvatarProvider) as a
 * small circle in front of their name. Used by the users list and every list
 * of users on a record (UsersRelationManager).
 *
 *   UserAvatarColumn::make()
 */
class UserAvatarColumn extends ImageColumn
{
    public static function getDefaultName(): ?string
    {
        return 'avatar';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('')
            ->state(fn (User $record): ?string => filament()->getUserAvatarUrl($record))
            ->circular()
            ->imageSize(32)
            ->grow(false);
    }
}
