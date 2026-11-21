<?php

namespace App\Filament\Navigation;

use Filament\Navigation\NavigationGroup as PanelNavigationGroup;
use Filament\Support\Contracts\HasLabel;

/**
 * The admin's navigation groups, in sidebar order. Resources and pages (also
 * a plugin's) set `$navigationGroup = NavigationGroup::Gallery` etc.; the
 * labels are translated per request.
 */
enum NavigationGroup implements HasLabel
{
    case Gallery;
    case UsersAndAccess;
    case AppearanceAndExtensions;
    case System;
    case Help;

    /**
     * The groups for the panel, keyed like Filament keys enum groups. The
     * labels are closures: the panel is configured before the request's
     * language is known (Filament's own enum support would fix them then).
     *
     * @return array<string, PanelNavigationGroup>
     */
    public static function panelGroups(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $group) => [$group->name => PanelNavigationGroup::make()->label(fn (): string => $group->getLabel())])
            ->all();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Gallery => __('Gallery'),
            self::UsersAndAccess => __('Users & access'),
            self::AppearanceAndExtensions => __('Appearance & extensions'),
            self::System => __('System'),
            self::Help => __('Help'),
        };
    }
}
