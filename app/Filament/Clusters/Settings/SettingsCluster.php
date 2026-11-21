<?php

namespace App\Filament\Clusters\Settings;

use App\Enums\Permission;
use App\Filament\Navigation\NavigationGroup;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin → Settings: one entry in the main navigation that opens an area with
 * its own menu. Each topic is a page in Pages/ extending SettingsPage, so a
 * new topic is a new class and nothing else has to change.
 */
class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings';

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Settings');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageSettings->value) ?? false;
    }
}
