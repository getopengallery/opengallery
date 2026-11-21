<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Photos\PhotoResource;
use App\Support\Dashboard\AlbumStats;

/** The viewer's latest uploads as thumbnails (people who may upload photos). */
class RecentUploads extends DashboardWidget
{
    protected string $view = 'filament.widgets.recent-uploads';

    public static function dashboardLabel(): string
    {
        return __('Your recent uploads');
    }

    public static function dashboardDescription(): ?string
    {
        return __('Thumbnails of the photos you uploaded last.');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::UploadPhotos);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = static::viewer();

        return [
            ...AlbumStats::recentUploads($user),
            'allUrl' => PhotoResource::getUrl('index', ['filters' => ['uploader' => ['value' => $user->id]]]),
        ];
    }
}
