<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Support\Dashboard\DashboardLayout;
use App\Support\DashboardStats;
use App\Support\SiteSettings;
use Carbon\CarbonImmutable;

/**
 * Album views (members and guests) and logins per week for the last 12
 * weeks. Each measure leads with the last full week and how it compares to
 * the week before; the current, unfinished week is drawn apart (hatched).
 */
class ActivityTrends extends DashboardWidget
{
    protected string $view = 'filament.widgets.activity-trends';

    protected static bool $isLazy = true;

    public static function dashboardLabel(): string
    {
        return __('Trends');
    }

    public static function dashboardDescription(): ?string
    {
        return __('Album views and logins per week.');
    }

    public static function defaultSize(): string
    {
        return DashboardLayout::FULL;
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ViewActivity);
    }

    /**
     * How $current compares to $previous, in words ("18% more than the week before").
     */
    public static function change(int $current, int $previous): ?string
    {
        if ($previous === 0) {
            return null;
        }

        $percent = (int) round(abs($current - $previous) / $previous * 100);

        return match (true) {
            $percent === 0 => __('About the same as the week before'),
            $current > $previous => __(':percent% more than the week before', ['percent' => $percent]),
            default => __(':percent% fewer than the week before', ['percent' => $percent]),
        };
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $weeks = DashboardStats::trends();
        $views = fn (array $week): int => $week['memberViews'] + $week['guestViews'];
        [$before, $last] = array_slice($weeks, -3, 2);
        $lastViews = $views($last);

        return [
            'weeks' => $weeks,
            'maxViews' => max(1, ...array_map($views, $weeks)),
            'maxLogins' => max(1, ...array_map(fn (array $week) => $week['logins'], $weeks)),
            'views' => ['value' => $lastViews, 'change' => self::change($lastViews, $views($before))],
            'logins' => ['value' => $last['logins'], 'change' => self::change($last['logins'], $before['logins'])],
            'guestShare' => $lastViews > 0 ? (int) round($last['guestViews'] / $lastViews * 100) : null,
            'firstWeek' => $weeks[0]['start'],
            'today' => CarbonImmutable::today(),
            'level' => SiteSettings::activityLevel(),
        ];
    }
}
