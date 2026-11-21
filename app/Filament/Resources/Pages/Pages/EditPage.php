<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Actions\OpenOnSiteAction;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Models\Page;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Page $page */
        $page = $this->getRecord();
        PageForm::ensurePhotosAreUsable($data, auth()->user(), $page);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            OpenOnSiteAction::make()
                ->url(fn (Page $record) => route('pages.show', $record))
                ->visible(fn (Page $record) => $record->is_published),
            DeleteAction::make(),
        ];
    }
}
