<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

/** After creating, the edit page opens so users can be given the role. */
class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function getRedirectUrl(): string
    {
        return RoleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * Permissions are saved per group by their own fields (RoleResource), not as role columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['permission_groups']);

        return $data;
    }
}
