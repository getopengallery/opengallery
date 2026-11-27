<?php

namespace App\Filament\Pages;

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Extensions\Manifest;
use App\Extensions\PluginManager;
use App\Filament\Actions\UploadExtensionAction;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Tables\EmptyState;
use App\Filament\Tables\ExtensionsTable;
use App\Filament\Tables\FilterBar;
use App\Support\ActivityLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Appearance & extensions → Plugins: every installed plugin (folders in
 * /plugins) as a list, with a switch to turn it on or off. A click on a row
 * opens the plugin's settings.
 */
class Plugins extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AppearanceAndExtensions;

    protected static ?int $navigationSort = 2;

    public function getTitle(): string
    {
        return __('Plugins');
    }

    public function getSubheading(): Htmlable
    {
        return new HtmlString(__('Plugins are folders in :folder with a :file. Only enable plugins from sources you trust: they run with full access to the site.', ['folder' => '<code>/plugins</code>', 'file' => '<code>plugin.json</code>']));
    }

    public static function getNavigationLabel(): string
    {
        return __('Plugins');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManagePlugins->value) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [UploadExtensionAction::make()->plugins()];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $plugins = app(PluginManager::class);

        return $table
            ->records(fn (?string $search, array $filters): array => ExtensionsTable::records(
                $plugins->all(),
                $search,
                fn (Manifest $plugin): array => [
                    'active' => $plugins->isEnabled($plugin->slug),
                    'locked' => $plugins->isEnabledByConfig($plugin->slug),
                ],
                fn (array $row): bool => match ($filters['status']['value'] ?? null) {
                    'active' => $row['active'],
                    'inactive' => ! $row['active'],
                    default => true,
                },
            ))
            ->searchable()
            ->paginated(false)
            // A click on a row opens the plugin's settings, while it is on and has some.
            ->recordUrl(fn (array $record): ?string => $record['active'] ? $this->settingsUrl($record['slug']) : null)
            ->columns([
                ...ExtensionsTable::columns(),
                ToggleColumn::make('active')
                    ->label(__('Active'))
                    ->disabled(fn (array $record): bool => $record['locked'])
                    ->tooltip(fn (array $record): ?string => $record['locked'] ? __('Enabled in the server configuration') : null)
                    ->updateStateUsing(function (array $record, mixed $state): void {
                        if ((bool) $state !== $record['active']) {
                            $this->toggle($record['slug']);
                        }
                    }),
            ])
            ->tap(fn (Table $table) => FilterBar::configure($table, primary: ['status']))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->placeholder(__('All'))
                    ->options(['active' => __('Active'), 'inactive' => __('Inactive')]),
            ])
            ->recordActions([
                // Same target as the row; the icon shows which plugins have settings.
                Action::make('settings')
                    ->label(__('Settings'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->iconButton()
                    ->url(fn (array $record): ?string => $record['active'] ? $this->settingsUrl($record['slug']) : null)
                    ->visible(fn (array $record): bool => $record['active'] && $this->settingsUrl($record['slug']) !== null),
            ])
            ->tap(fn (Table $table) => EmptyState::apply($table, __('No plugins are installed.'), null, Heroicon::OutlinedPuzzlePiece));
    }

    public function isEnabledByConfig(string $slug): bool
    {
        return app(PluginManager::class)->isEnabledByConfig($slug);
    }

    /** URL of the plugin's settings page, if it has one and is loaded. */
    public function settingsUrl(string $slug): ?string
    {
        $page = app(PluginManager::class)->settingsPage($slug);

        return $page ? $page::getUrl() : null;
    }

    public function isEnabled(string $slug): bool
    {
        return app(PluginManager::class)->isEnabled($slug);
    }

    public function toggle(string $slug): void
    {
        abort_unless(static::canAccess(), 403);

        $plugins = app(PluginManager::class);
        abort_unless($plugins->all()->has($slug), 404);
        abort_if($plugins->isEnabledByConfig($slug), 422, __('This plugin is enabled in the server configuration.'));
        $enable = ! $plugins->isEnabled($slug);
        $enable ? $plugins->enable($slug) : $plugins->disable($slug);
        ActivityLog::extensionChanged($enable ? ActivityType::PluginEnabled : ActivityType::PluginDisabled, $plugins->all()->get($slug)?->name ?? $slug);

        Notification::make()
            ->title($enable ? __('Plugin enabled') : __('Plugin disabled'))
            ->success()
            ->send();

        // Plugins are loaded per request: reload, so a plugin's settings page and
        // navigation entries are there (or gone) right away.
        $this->redirect(static::getUrl());
    }
}
