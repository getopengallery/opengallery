<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Documentation;
use App\Filament\Pages\Plugins;
use App\Filament\Pages\ThemeSettings;
use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Users\UserResource;
use App\Support\Dashboard\DashboardLayout;
use Filament\Support\Icons\Heroicon;

/**
 * Greeting and shortcuts for every admin user, built from what they may
 * open (each target's own canAccess()/canCreate()), so nobody lands on an
 * empty dashboard. More album and upload shortcuts live in "Your albums".
 */
class QuickActions extends DashboardWidget
{
    protected string $view = 'filament.widgets.quick-actions';

    public static function dashboardLabel(): string
    {
        return __('Welcome and shortcuts');
    }

    public static function dashboardDescription(): ?string
    {
        return __('Shortcuts to the parts of the admin you may use.');
    }

    public static function defaultSize(): string
    {
        return DashboardLayout::FULL;
    }

    public static function canView(): bool
    {
        return static::viewer() !== null;
    }

    /** @return list<array{label: string, url: string, icon: Heroicon}> */
    protected function actions(): array
    {
        return array_values(array_filter([
            AlbumResource::canAccess() && AlbumResource::canCreate() ? ['label' => __('New album'), 'url' => AlbumResource::getUrl('create'), 'icon' => Heroicon::OutlinedFolderPlus] : null,
            PageResource::canAccess() && PageResource::canCreate() ? ['label' => __('New page'), 'url' => PageResource::getUrl('create'), 'icon' => Heroicon::OutlinedDocumentPlus] : null,
            UserResource::canAccess() && UserResource::canCreate() ? ['label' => __('Add user'), 'url' => UserResource::getUrl('create'), 'icon' => Heroicon::OutlinedUserPlus] : null,
            ['label' => __('Media library'), 'url' => PhotoResource::getUrl(), 'icon' => Heroicon::OutlinedPhoto],
            ActivityEventResource::canAccess() ? ['label' => __('Activity log'), 'url' => ActivityEventResource::getUrl(), 'icon' => Heroicon::OutlinedClock] : null,
            SettingsCluster::canAccess() ? ['label' => __('Settings'), 'url' => SettingsCluster::getUrl(), 'icon' => Heroicon::OutlinedCog6Tooth] : null,
            ThemeSettings::canAccess() ? ['label' => __('Theme settings'), 'url' => ThemeSettings::getUrl(), 'icon' => Heroicon::OutlinedPaintBrush] : null,
            Plugins::canAccess() ? ['label' => __('Plugins'), 'url' => Plugins::getUrl(), 'icon' => Heroicon::OutlinedPuzzlePiece] : null,
            ['label' => __('Documentation'), 'url' => Documentation::getUrl(), 'icon' => Heroicon::OutlinedBookOpen],
            ['label' => __('View site'), 'url' => route('home'), 'icon' => Heroicon::OutlinedArrowTopRightOnSquare],
        ]));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = static::viewer();

        // Nothing but "access admin panel": say what the account can do.
        $onlyAdminAccess = ! $user->hasRole(Role::SuperAdmin->value)
            && $user->getAllPermissions()->pluck('name')->diff([Permission::AccessAdmin->value])->isEmpty();

        return [
            'name' => $user->name,
            'actions' => $this->actions(),
            'onlyAdminAccess' => $onlyAdminAccess,
        ];
    }
}
