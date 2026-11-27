<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Pages\Statistics;
use App\Support\Dashboard\DashboardLayout;
use App\Support\SiteSettings;
use App\Support\Statistics\VisitStats;

/**
 * Album views, downloads and logins of the last full week (Monday to
 * Sunday), each compared to the week before. Charts over longer periods are
 * on the statistics page, linked for people who may open it.
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
        return __('Album views, downloads and logins last week.');
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
        [$before, $last] = VisitStats::weeks(3);
        $views = fn (array $week): int => $week['memberViews'] + $week['guestViews'];
        $figure = fn (int $value, int $previous, string $label): array => ['value' => $value, 'label' => $label, 'change' => self::change($value, $previous)];

        return [
            'figures' => [
                $figure($views($last), $views($before), trans_choice('album view|album views', $views($last))),
                $figure($last['downloads'], $before['downloads'], trans_choice('download|downloads', $last['downloads'])),
                $figure($last['logins'], $before['logins'], trans_choice('login|logins', $last['logins'])),
            ],
            'level' => SiteSettings::activityLevel(),
            'more' => Statistics::canAccess() ? Statistics::getUrl() : null,
        ];
    }
}
