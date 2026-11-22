<?php

namespace App\Filament\Clusters\Settings;

use App\Enums\Permission;
use Filament\Clusters\Cluster;

/**
 * The settings pages' shared address (/admin/settings/...) and access check.
 * Each topic is a page in Pages/ extending SettingsPage and is listed directly
 * in the main navigation (groups Settings and System), so the cluster has no
 * menu of its own and a new topic is a new class.
 */
class SettingsCluster extends Cluster
{
    protected static bool $shouldRegisterNavigation = false;

    protected static bool $shouldRegisterSubNavigation = false;

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
