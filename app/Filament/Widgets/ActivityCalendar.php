<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Support\Dashboard\DashboardLayout;
use App\Support\DashboardStats;
use App\Support\SiteSettings;

/**
 * Contribution-style calendar: one square per day for the last year,
 * shaded by how many members (or anonymous guests) were active.
 */
class ActivityCalendar extends DashboardWidget
{
    protected string $view = 'filament.widgets.activity-calendar';

    public static function dashboardLabel(): string
    {
        return __('Activity');
    }

    public static function dashboardDescription(): ?string
    {
        return __('When members and guests were active in the last year.');
    }

    public static function defaultSize(): string
    {
        return DashboardLayout::FULL;
    }

    /** The heaviest query of the dashboard (cached in DashboardStats), loaded after the page. */
    protected static bool $isLazy = true;

    public string $mode = DashboardStats::MEMBERS;

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ViewActivity);
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode === DashboardStats::GUESTS ? DashboardStats::GUESTS : DashboardStats::MEMBERS;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'calendar' => DashboardStats::calendar($this->mode),
            'level' => SiteSettings::activityLevel(),
        ];
    }
}
