<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Enums\StatisticsRange;
use App\Filament\Pages\Statistics;
use App\Filament\Resources\Albums\AlbumResource;
use App\Models\Album;
use App\Support\Statistics\VisitStats;

/**
 * The five most viewed albums of the last 30 days, among those the viewer
 * may list. The statistics page has the longer list and other periods.
 */
class PopularAlbums extends DashboardWidget
{
    protected string $view = 'filament.widgets.popular-albums';

    public static function dashboardLabel(): string
    {
        return __('Most viewed albums');
    }

    public static function dashboardDescription(): ?string
    {
        return __('The albums opened most often in the last 30 days.');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ViewActivity);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $viewer = static::viewer();

        return [
            'rows' => VisitStats::topAlbums(StatisticsRange::Month, $viewer, 5),
            'more' => Statistics::canAccess() ? Statistics::getUrl() : null,
            // Albums the viewer may edit open in the admin, the others on the site.
            'url' => fn (Album $album): string => $viewer->can('update', $album)
                ? AlbumResource::getUrl('edit', ['record' => $album])
                : route('albums.show', $album),
        ];
    }
}
