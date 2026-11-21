<?php

namespace Plugins\Example\Filament;

use App\Enums\Permission;
use App\Filament\Widgets\DashboardWidget;

/**
 * Dashboard widget of the Example plugin, registered in
 * ExampleServiceProvider::register() under the key "example.hello".
 * Like every dashboard widget, its canView() decides who sees it.
 */
class ExampleWidget extends DashboardWidget
{
    protected string $view = 'example::example-widget';

    public static function dashboardLabel(): string
    {
        return __('example::example.widget_heading');
    }

    public static function dashboardDescription(): ?string
    {
        return __('example::example.widget_description');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ManagePlugins);
    }
}
