<?php

namespace App\Filament\Pages;

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Extensions\Manifest;
use App\Extensions\ThemeManager;
use App\Filament\Actions\UploadExtensionAction;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Tables\EmptyState;
use App\Filament\Tables\ExtensionsTable;
use App\Support\ActivityLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Appearance & extensions → Themes: every installed theme (folders in
 * /themes) as a list, with a colour preview and a button to activate it. A click
 * on a row opens the theme's settings.
 */
class Themes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AppearanceAndExtensions;

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return __('Themes');
    }

    public function getSubheading(): Htmlable
    {
        return new HtmlString(__('Themes are folders in :folder with a :file. The Default theme shows everything a theme can make configurable; copy it to start your own.', ['folder' => '<code>/themes</code>', 'file' => '<code>theme.json</code>']));
    }

    public static function getNavigationLabel(): string
    {
        return __('Themes');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageThemes->value) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [UploadExtensionAction::make()->themes()];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $themes = app(ThemeManager::class);

        return $table
            // The theme in use comes first, then the others by name.
            ->records(fn (?string $search): array => collect(ExtensionsTable::records(
                $themes->all(),
                $search,
                fn (Manifest $theme): array => [
                    'active' => $this->getActiveSlug() === $theme->slug,
                    // The first colours of the theme's palette, as a preview.
                    'swatches' => collect((array) $theme->get('variables', []))
                        ->filter(fn (mixed $value): bool => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1)
                        ->take(5)
                        ->values()
                        ->all(),
                ],
            ))->sortByDesc('active')->all())
            ->searchable()
            ->paginated(false)
            // A click on a row opens the theme's settings (also for inactive themes, to prepare one).
            ->recordUrl(fn (array $record): ?string => $this->settingsUrl($record['slug']))
            ->columns([
                ...ExtensionsTable::columns(),
                ViewColumn::make('swatches')
                    ->label(__('Colours'))
                    ->view('filament.tables.theme-swatches')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                // One theme is always active: activating another one replaces it. A quiet
                // button, so the list is not a row of calls to action; it asks first,
                // because visitors see the change right away.
                Action::make('activate')
                    ->label(__('Activate'))
                    ->button()
                    ->outlined()
                    ->color('gray')
                    ->size(Size::Small)
                    ->requiresConfirmation()
                    ->modalIcon(Heroicon::OutlinedSwatch)
                    ->modalIconColor('primary')
                    ->modalHeading(fn (array $record): string => __('Activate :theme?', ['theme' => $record['name']]))
                    ->modalDescription(__('Visitors see the new look right away. Each theme keeps its own settings, so you can switch back at any time.'))
                    ->modalSubmitActionLabel(__('Activate'))
                    ->visible(fn (array $record): bool => ! $record['active'])
                    ->action(fn (array $record) => $this->activate($record['slug'])),
                // Marks the theme in use in the same place. Primary is the active
                // theme's own colour here, since the admin follows the active theme.
                Action::make('active')
                    ->label(__('Active'))
                    ->badge()
                    ->color('primary')
                    ->icon(Heroicon::CheckCircle)
                    ->disabled()
                    ->tooltip(__('One theme is always active. Activate another theme to switch.'))
                    ->extraAttributes(['class' => 'fi-force-enabled'])
                    ->visible(fn (array $record): bool => $record['active']),
                // Same target as the row; the icon shows which themes have settings.
                Action::make('settings')
                    ->label(__('Settings'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->iconButton()
                    ->url(fn (array $record): ?string => $this->settingsUrl($record['slug']))
                    ->visible(fn (array $record): bool => $this->settingsUrl($record['slug']) !== null),
            ])
            ->tap(fn (Table $table) => EmptyState::apply($table, __('No themes are installed.'), null, Heroicon::OutlinedSwatch));
    }

    /** Settings page URL for a theme that declares settings in its theme.json. */
    public function settingsUrl(string $slug): ?string
    {
        return app(ThemeManager::class)->settingSections($slug) !== [] ? ThemeSettings::getUrl(['theme' => $slug]) : null;
    }

    public function getActiveSlug(): ?string
    {
        return app(ThemeManager::class)->active()?->slug;
    }

    public function activate(string $slug): void
    {
        abort_unless(static::canAccess(), 403);

        $themes = app(ThemeManager::class);
        $themes->activate($slug);
        ActivityLog::extensionChanged(ActivityType::ThemeActivated, $themes->all()->get($slug)->name);

        Notification::make()->title(__('Theme activated'))->success()->send();

        // Reload, so the admin takes on the new theme's colours too.
        $this->redirect(static::getUrl());
    }
}
