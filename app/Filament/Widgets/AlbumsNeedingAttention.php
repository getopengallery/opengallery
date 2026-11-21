<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Support\Dashboard\AlbumStats;

/**
 * For staff who may edit every album: albums that are empty, have no cover
 * or whose share link runs out (Album::manageableBy()).
 */
class AlbumsNeedingAttention extends DashboardWidget
{
    protected string $view = 'filament.widgets.albums-needing-attention';

    public static function dashboardLabel(): string
    {
        return __('Albums needing attention');
    }

    public static function dashboardDescription(): ?string
    {
        return __('Empty albums, albums without a cover and share links that run out.');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::EditAnyAlbum);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return AlbumStats::needingAttention(static::viewer());
    }
}
