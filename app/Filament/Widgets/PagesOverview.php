<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Pages\PageResource;
use App\Support\Dashboard\PageStats;

/** For people who edit pages: published, drafts, members-only and the latest edits. */
class PagesOverview extends DashboardWidget
{
    protected string $view = 'filament.widgets.pages-overview';

    public static function dashboardLabel(): string
    {
        return __('Pages');
    }

    public static function dashboardDescription(): ?string
    {
        return __('Drafts and recently edited pages.');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ManagePages);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            ...PageStats::overview(),
            'newPageUrl' => PageResource::canCreate() ? PageResource::getUrl('create') : null,
            'pagesUrl' => PageResource::getUrl(),
        ];
    }
}
