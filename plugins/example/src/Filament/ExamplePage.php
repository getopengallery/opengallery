<?php

namespace Plugins\Example\Filament;

use App\Enums\Permission;
use App\Filament\Navigation\NavigationGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * An admin page added by a plugin (registered in ExampleServiceProvider::register()
 * through Panel::configureUsing()). Resources and widgets are added the same way.
 */
class ExamplePage extends Page
{
    protected string $view = 'example::example-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AppearanceAndExtensions;

    protected static ?string $slug = 'plugins/example/hello';

    public static function getNavigationLabel(): string
    {
        return __('example::example.navigation');
    }

    public function getTitle(): string
    {
        return __('example::example.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManagePlugins->value) ?? false;
    }
}
