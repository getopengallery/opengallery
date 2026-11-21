<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Users\UserResource;
use App\Support\Dashboard\DashboardLayout;
use App\Support\DashboardStats;
use Filament\Resources\Resource;
use Illuminate\Support\Number;

/**
 * One-line ledger of what the gallery holds. Counts the whole library (only
 * numbers, no names), so it needs "view activity".
 */
class LibraryOverview extends DashboardWidget
{
    protected string $view = 'filament.widgets.library-overview';

    public static function dashboardLabel(): string
    {
        return __('Library figures');
    }

    public static function dashboardDescription(): ?string
    {
        return __('How many albums, photos, members and groups there are.');
    }

    public static function defaultSize(): string
    {
        return DashboardLayout::FULL;
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ViewActivity);
    }

    /** @return list<array{value: string, label: string, url: ?string}> */
    public function getFigures(): array
    {
        $t = DashboardStats::totals();

        return [
            ['value' => Number::format($t['albums']), 'label' => trans_choice('album|albums', $t['albums']), 'url' => self::urlIfAllowed(AlbumResource::class)],
            ['value' => Number::format($t['photos']), 'label' => trans_choice('photo|photos', $t['photos']), 'url' => self::urlIfAllowed(PhotoResource::class)],
            ['value' => Number::format($t['users']), 'label' => trans_choice('member|members', $t['users']), 'url' => self::urlIfAllowed(UserResource::class)],
            ['value' => Number::format($t['groups']), 'label' => trans_choice('group|groups', $t['groups']), 'url' => self::urlIfAllowed(GroupResource::class)],
            ['value' => Number::fileSize($t['bytes'], precision: 1), 'label' => __('of photos stored'), 'url' => null],
        ];
    }

    /**
     * A figure links to its list only for people who may open that list.
     *
     * @param  class-string<resource>  $resource
     */
    private static function urlIfAllowed(string $resource): ?string
    {
        return $resource::canAccess() ? $resource::getUrl() : null;
    }
}
