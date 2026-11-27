<?php

namespace App\Filament\Resources\Licenses\Pages;

use App\Filament\Resources\Licenses\LicenseResource;
use Filament\Resources\Pages\EditRecord;

class EditLicense extends EditRecord
{
    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LicenseResource::deleteAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return LicenseResource::getUrl('index');
    }
}
