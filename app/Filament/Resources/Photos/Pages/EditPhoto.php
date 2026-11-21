<?php

namespace App\Filament\Resources\Photos\Pages;

use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Trash\TrashActions;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPhoto extends EditRecord
{
    protected static string $resource = PhotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            TrashActions::moveToTrash()->modalDescription(self::trashDescription()),
            TrashActions::restore(),
            TrashActions::deletePermanently(),
        ];
    }

    /** What "Move to trash" does to a photo (ViewPhoto says the same). */
    public static function trashDescription(): string
    {
        return __('It is removed from every album it is in. You can restore it from the trash.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
