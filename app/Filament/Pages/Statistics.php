<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\StatisticsRange;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Album;
use App\Models\User;
use App\Support\SiteSettings;
use App\Support\Statistics\ExposureStats;
use App\Support\Statistics\LibraryStats;
use App\Support\Statistics\MemberActivityStats;
use App\Support\Statistics\VisitStats;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Site-wide figures for administrators ("View statistics"): visits and
 * downloads over a chosen period, the activity calendar, album and photo
 * rankings, disk space, growth, member activity and what anonymous visitors
 * can see. The dashboard shows short summaries of some of them and links here.
 * Queries live in App\Support\Statistics and are cached briefly.
 */
class Statistics extends Page
{
    protected string $view = 'filament.pages.statistics';

    protected static ?string $slug = 'statistics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 60;

    /** Days of the period shown (a StatisticsRange value). */
    #[Url]
    public int $range = StatisticsRange::Month->value;

    /** Whose activity the calendar shows (VisitStats::MEMBERS or GUESTS). */
    public string $calendar = VisitStats::MEMBERS;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ViewStatistics->value) ?? false;
    }

    public function getTitle(): string
    {
        return __('Statistics');
    }

    public static function getNavigationLabel(): string
    {
        return __('Statistics');
    }

    public function setRange(int $days): void
    {
        $this->range = (StatisticsRange::tryFrom($days) ?? StatisticsRange::Month)->value;
    }

    public function setCalendar(string $mode): void
    {
        $this->calendar = $mode === VisitStats::GUESTS ? VisitStats::GUESTS : VisitStats::MEMBERS;
    }

    public function period(): StatisticsRange
    {
        return StatisticsRange::tryFrom($this->range) ?? StatisticsRange::Month;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $period = $this->period();
        $viewer = auth()->user();
        $series = VisitStats::series($period);

        return [
            'period' => $period,
            'ranges' => StatisticsRange::cases(),
            'level' => SiteSettings::activityLevel(),
            'retentionDays' => SiteSettings::activityRetentionDays(),
            'visits' => VisitStats::totals($period),
            'series' => $series,
            'activityCalendar' => VisitStats::calendar($this->calendar),
            'topAlbums' => VisitStats::topAlbums($period, $viewer),
            'unopened' => VisitStats::unopenedAlbums($period),
            'topDownloads' => VisitStats::topDownloads($period),
            'storage' => LibraryStats::storage(),
            'storageByAlbum' => LibraryStats::storageByAlbum(),
            'storageByUploader' => LibraryStats::storageByUploader(),
            'growth' => LibraryStats::growth($period),
            'members' => MemberActivityStats::overview(),
            'leastActive' => MemberActivityStats::leastActive(),
            'inactiveDays' => MemberActivityStats::INACTIVE_DAYS,
            'exposure' => ExposureStats::overview(),
            'userUrl' => fn (User $user): ?string => UserResource::canAccess() ? UserResource::getUrl('edit', ['record' => $user]) : null,
            // Albums the viewer may edit open in the admin, the others on the site.
            'albumUrl' => fn (Album $album): string => $viewer->can('update', $album)
                ? AlbumResource::getUrl('edit', ['record' => $album])
                : route('albums.show', $album),
        ];
    }
}
