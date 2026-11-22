<?php

namespace App\Filament\Resources\ActivityEvents\Pages;

use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageActivityEvents extends ManageRecords
{
    protected static string $resource = ActivityEventResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('logSettings')
                ->label(__('Log settings'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->url(fn (): string => ActivityLogSettings::getUrl())
                ->visible(fn (): bool => ActivityLogSettings::canAccess()),
        ];
    }

    public function getSubheading(): ?string
    {
        return __('Logins, album views and changes made by members and staff. No IP addresses are stored; guests appear anonymously.');
    }
}
