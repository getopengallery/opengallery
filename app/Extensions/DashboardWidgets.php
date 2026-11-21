<?php

namespace App\Extensions;

use App\Filament\Widgets\ActivityCalendar;
use App\Filament\Widgets\ActivityTrends;
use App\Filament\Widgets\AlbumsNeedingAttention;
use App\Filament\Widgets\DashboardWidget;
use App\Filament\Widgets\LibraryOverview;
use App\Filament\Widgets\MembersOverview;
use App\Filament\Widgets\PagesOverview;
use App\Filament\Widgets\PopularAlbums;
use App\Filament\Widgets\QuickActions;
use App\Filament\Widgets\RecentActivity;
use App\Filament\Widgets\RecentUploads;
use App\Filament\Widgets\SiteHealth;
use App\Filament\Widgets\TrashOverview;
use App\Filament\Widgets\YourAlbums;
use InvalidArgumentException;

/**
 * The widgets of the admin dashboard, each under a stable key. Core widgets
 * are listed in CORE; plugins add theirs from their service provider's
 * register() (the admin panel is set up after plugins are registered):
 *
 *   app(DashboardWidgets::class)->register('my-plugin.stats', StatsWidget::class, sort: 150);
 *
 * Keys are lowercase words joined by "-" or "."; plugins prefix theirs with
 * the plugin slug; the key (not the class) is what people's saved layouts
 * refer to. A plugin that is turned off is not loaded, so its widgets
 * disappear. Who sees a widget is decided by the widget's own canView();
 * widgets extend App\Filament\Widgets\DashboardWidget.
 */
class DashboardWidgets
{
    /** @var array<string, array{0: class-string<DashboardWidget>, 1: int}> key => [widget, default position] */
    public const CORE = [
        'quick-actions' => [QuickActions::class, 0],
        'your-albums' => [YourAlbums::class, 10],
        'recent-uploads' => [RecentUploads::class, 20],
        'albums-attention' => [AlbumsNeedingAttention::class, 30],
        'trash' => [TrashOverview::class, 40],
        'site-health' => [SiteHealth::class, 50],
        'members' => [MembersOverview::class, 60],
        'pages' => [PagesOverview::class, 70],
        'library' => [LibraryOverview::class, 80],
        'activity-calendar' => [ActivityCalendar::class, 90],
        'activity-trends' => [ActivityTrends::class, 100],
        'recent-activity' => [RecentActivity::class, 110],
        'popular-albums' => [PopularAlbums::class, 120],
    ];

    /** @var array<string, array{widget: class-string<DashboardWidget>, sort: int}> */
    private array $widgets = [];

    public function __construct()
    {
        foreach (self::CORE as $key => [$widget, $sort]) {
            $this->register($key, $widget, $sort);
        }
    }

    /**
     * Adds (or replaces) the widget under $key. $sort is its default
     * position: core widgets use 0–120, so plugins usually pick 200 or more.
     *
     * @param  class-string<DashboardWidget>  $widget
     */
    public function register(string $key, string $widget, int $sort = 200): void
    {
        if (! preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $key)) {
            throw new InvalidArgumentException("Invalid dashboard widget key [{$key}].");
        }

        if (! is_subclass_of($widget, DashboardWidget::class)) {
            throw new InvalidArgumentException("[{$widget}] does not extend ".DashboardWidget::class.'.');
        }

        $this->widgets[$key] = ['widget' => $widget, 'sort' => $sort];
    }

    /** @return array<string, class-string<DashboardWidget>> key => widget, in their default order */
    public function all(): array
    {
        $widgets = $this->widgets;
        uksort($widgets, fn (string $a, string $b) => [$widgets[$a]['sort'], $a] <=> [$widgets[$b]['sort'], $b]);

        return array_map(fn (array $entry) => $entry['widget'], $widgets);
    }

    /** @return list<class-string<DashboardWidget>> */
    public function classes(): array
    {
        return array_values($this->all());
    }

    /** The key a widget is registered under. */
    public function keyOf(string $widget): ?string
    {
        $key = array_search($widget, $this->all(), true);

        return $key === false ? null : $key;
    }
}
