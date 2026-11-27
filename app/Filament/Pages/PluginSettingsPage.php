<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Extensions\PluginManager;
use App\Extensions\PluginSettings;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Base class for a plugin's settings page in the admin.
 *
 * Declare it in plugin.json ("settings": "Plugins\\Foo\\Filament\\Settings"),
 * set $plugin to the plugin's slug, implement form() and save().
 * It is reachable at /admin/plugins/{slug}/settings via the gear icon on the
 * plugin's card, only while the plugin is enabled and only for staff who may
 * manage plugins.
 */
abstract class PluginSettingsPage extends Page
{
    /** The plugin's slug (its folder name in /plugins). */
    protected static string $plugin;

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    abstract public function save(): void;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'plugins/'.static::$plugin.'/settings';
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->can(Permission::ManagePlugins->value) ?? false)
            && app(PluginManager::class)->isEnabled(static::$plugin);
    }

    public function getTitle(): string|Htmlable
    {
        return app(PluginManager::class)->all()->get(static::$plugin)?->label() ?? __('Plugin settings');
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [Plugins::getUrl() => __('Plugins'), __('Settings')];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Save'))->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    protected static function settings(string $key, mixed $default = null): mixed
    {
        return PluginSettings::get(static::$plugin, $key, $default);
    }

    protected static function putSettings(string $key, mixed $value): void
    {
        PluginSettings::put(static::$plugin, $key, $value);
    }
}
