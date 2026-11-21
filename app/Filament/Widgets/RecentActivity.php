<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Support\Dashboard\DashboardLayout;
use App\Support\DashboardStats;

/**
 * The latest activity log entries, without those about albums or photos the
 * viewer cannot see (DashboardStats::recent()).
 */
class RecentActivity extends DashboardWidget
{
    protected string $view = 'filament.widgets.recent-activity';

    public static function dashboardLabel(): string
    {
        return __('Recent activity');
    }

    public static function dashboardDescription(): ?string
    {
        return __('The latest entries of the activity log.');
    }

    public static function defaultSize(): string
    {
        return DashboardLayout::WIDE;
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ViewActivity);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['events' => DashboardStats::recent(static::viewer())];
    }
}
