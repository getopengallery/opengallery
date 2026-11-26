<?php

namespace App\Filament\Resources\Photos\Pages;

use App\Filament\Resources\Photos\Pages\Concerns\PhotoPageActions;
use App\Filament\Resources\Photos\PhotoResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The photo page for people who may edit the photo. A photo in the trash is
 * restored first, so it opens read-only on ViewPhoto.
 */
class EditPhoto extends EditRecord
{
    use PhotoPageActions;

    protected static string $resource = PhotoResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->getRecord()->trashed()) {
            $this->redirect(PhotoResource::getUrl('view', ['record' => $this->getRecord()]));
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

    /** Stay on the photo after saving. */
    protected function getRedirectUrl(): ?string
    {
        return null;
    }
}
