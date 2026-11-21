<?php

namespace App\Filament\Resources\Photos\Pages;

use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Trash\TrashActions;
use App\Models\Photo;
use App\Support\AlbumAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewPhoto extends ViewRecord
{
    protected static string $resource = PhotoResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->title ?: __('Untitled photo');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make($this->getRecord()->activeAlbums()
                ->map(fn ($album) => Action::make('open-'.$album->id)
                    ->label($album->path())
                    ->url(route('albums.show', $album))
                    ->openUrlInNewTab())
                ->all())
                ->label(__('Open in album'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->button()
                ->visible(! $this->getRecord()->trashed() && $this->getRecord()->activeAlbums()->isNotEmpty()),
            Action::make('download')
                ->label(__('Download original'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (Photo $record) => route('media.show', ['media' => $record->original(), 'download' => 1]))
                ->visible(fn (Photo $record) => $record->original() !== null && AlbumAccess::canDownload(auth()->user(), $record)),
            // A photo in the trash is restored first, then changed.
            EditAction::make()->hidden(fn (Photo $record): bool => $record->trashed()),
            TrashActions::moveToTrash()->modalDescription(EditPhoto::trashDescription()),
            TrashActions::restore(),
            TrashActions::deletePermanently(),
        ];
    }
}
