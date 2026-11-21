<?php

namespace App\Filament\Resources\ActivityEvents\Pages;

use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageActivityEvents extends ManageRecords
{
    protected static string $resource = ActivityEventResource::class;

    public function getSubheading(): ?string
    {
        return __('Logins, album views and changes made by members and staff. No IP addresses are stored; guests appear anonymously.');
    }
}
