<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Enums\Role as BuiltInRole;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn () => BuiltInRole::tryFrom($this->getRecord()->name) !== null),
        ];
    }

    /**
     * Permissions are saved per group by their own fields (RoleResource), not as role columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['permission_groups']);

        return $data;
    }
}
