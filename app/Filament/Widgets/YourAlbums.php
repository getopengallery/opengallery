<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Support\Dashboard\AlbumStats;

/**
 * For everyone who creates albums or uploads photos: "New album", "Upload
 * photos", their most recently changed albums with what needs a look
 * (empty, link expiring), and photos waiting in "Unsorted uploads".
 */
class YourAlbums extends DashboardWidget
{
    protected string $view = 'filament.widgets.your-albums';

    public static function dashboardLabel(): string
    {
        return __('Your albums');
    }

    public static function dashboardDescription(): ?string
    {
        return __('Your latest albums, photos waiting to be sorted, and buttons to upload or create.');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::CreateAlbums, Permission::UploadPhotos);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = static::viewer();
        $inbox = AlbumStats::inbox($user);

        return [
            'canCreate' => AlbumResource::canCreate(),
            'newAlbumUrl' => AlbumResource::canCreate() ? AlbumResource::getUrl('create') : null,
            'uploadUrl' => $user->can(Permission::UploadPhotos->value) ? PhotoResource::getUrl('index', ['action' => 'uploadPhotos']) : null,
            'albums' => AlbumStats::ownAlbums($user),
            'inbox' => $inbox,
            'inboxUrl' => $inbox ? PhotoResource::getUrl('index', ['filters' => ['albums' => ['values' => [$inbox['album']->id]]]]) : null,
            'allAlbumsUrl' => AlbumResource::getUrl(),
        ];
    }
}
