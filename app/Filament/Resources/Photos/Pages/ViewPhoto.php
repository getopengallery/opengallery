<?php

namespace App\Filament\Resources\Photos\Pages;

use App\Filament\Resources\Photos\Pages\Concerns\PhotoPageActions;
use App\Filament\Resources\Photos\PhotoResource;
use App\Models\Photo;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Read-only photo page, for photos in the trash and for people who may not
 * edit the photo. Anyone who may edit it is sent to EditPhoto, which is the
 * photo page for editors (same layout, editable fields).
 */
class ViewPhoto extends ViewRecord
{
    use PhotoPageActions;

    protected static string $resource = PhotoResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Photo $photo */
        $photo = $this->getRecord();

        if (PhotoResource::opensForEditing($photo)) {
            $this->redirect(PhotoResource::getUrl('edit', ['record' => $photo]));
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->title ?: __('Untitled photo');
    }

    protected function getHeaderActions(): array
    {
        return $this->photoPageActions();
    }
}
