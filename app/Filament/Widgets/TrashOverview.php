<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Trash\TrashActions;

/**
 * What waits in the trash for the viewer, with the same counts as the trash
 * buttons (TrashActions::count()). Shown only while there is something.
 */
class TrashOverview extends DashboardWidget
{
    protected string $view = 'filament.widgets.trash-overview';

    public static function dashboardLabel(): string
    {
        return __('Trash');
    }

    public static function dashboardDescription(): ?string
    {
        return __('What you could restore from the trash. Only shown while it holds something.');
    }

    public static function canView(): bool
    {
        if (! static::viewerCan(Permission::CreateAlbums, Permission::UploadPhotos, Permission::EditAnyAlbum, Permission::DeleteAnyAlbum)) {
            return false;
        }

        return array_sum(static::counts(auth()->id())) > 0;
    }

    /**
     * Counted once per request and viewer.
     *
     * @return array{albums: int, photos: int}
     */
    protected static function counts(int $userId): array
    {
        return once(fn () => [
            'albums' => TrashActions::count(AlbumResource::class),
            'photos' => TrashActions::count(PhotoResource::class),
        ]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'counts' => static::counts(auth()->id()),
            'albumsUrl' => AlbumResource::getUrl('trash'),
            'photosUrl' => PhotoResource::getUrl('trash'),
        ];
    }
}
