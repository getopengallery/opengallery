<?php

namespace App\Filament\Resources\Photos\Pages\Concerns;

use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Trash\TrashActions;
use App\Models\Photo;
use App\Support\AlbumAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

/**
 * Header actions of the photo page. Editors work on EditPhoto; ViewPhoto is
 * the read-only version for photos in the trash and people who may not edit
 * them. Both show the same actions so the page looks the same either way.
 */
trait PhotoPageActions
{
    /** What "Move to trash" does to a photo. */
    public static function trashDescription(): string
    {
        return __('It is removed from every album it is in. You can restore it from the trash.');
    }

    /** @return array<int, Action|ActionGroup> */
    protected function photoPageActions(): array
    {
        /** @var Photo $photo */
        $photo = $this->getRecord();

        return [
            ActionGroup::make($photo->activeAlbums()
                ->map(fn ($album) => Action::make('open-'.$album->id)
                    ->label($album->path())
                    ->url(route('albums.show', $album))
                    ->openUrlInNewTab())
                ->all())
                ->label(__('Open in album'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->button()
                ->visible(! $photo->trashed() && $photo->activeAlbums()->isNotEmpty()),
            Action::make('download')
                ->label(__('Download original'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (Photo $record) => route('media.show', ['media' => $record->original(), 'download' => 1]))
                ->visible(fn (Photo $record) => $record->original() !== null && AlbumAccess::canDownload(auth()->user(), $record)),
            TrashActions::moveToTrash()->modalDescription(self::trashDescription()),
            // A restored photo can be changed again, so editors move on to the edit page.
            TrashActions::restore()->successRedirectUrl(fn (Photo $record): string => PhotoResource::detailUrl($record)),
            TrashActions::deletePermanently(),
        ];
    }
}
