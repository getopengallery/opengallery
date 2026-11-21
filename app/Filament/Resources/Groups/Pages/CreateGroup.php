<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use Filament\Resources\Pages\CreateRecord;

/** After creating, the edit page opens so members can be added. */
class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    protected function getRedirectUrl(): string
    {
        return GroupResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
