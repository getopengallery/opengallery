<?php

namespace App\Filament\Resources\Licenses;

use App\Enums\Permission;
use App\Filament\Clusters\Settings\Pages\LicenseSettings;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\Licenses\Pages\CreateLicense;
use App\Filament\Resources\Licenses\Pages\EditLicense;
use App\Filament\Resources\Licenses\Pages\ListLicenses;
use App\Filament\Tables\EmptyState;
use App\Models\License;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The licenses photos can carry. Opened from Settings → Licenses, not listed
 * in the menu. The default license cannot be deactivated, and a license used
 * by a photo cannot be deleted (only deactivated).
 */
class LicenseResource extends Resource
{
    protected static ?string $model = License::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('license');
    }

    public static function getPluralModelLabel(): string
    {
        return __('licenses');
    }

    public static function getNavigationLabel(): string
    {
        return __('Licenses');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageSettings->value) ?? false;
    }

    /** @return array<string, string> */
    public static function settingsBreadcrumb(): array
    {
        return [LicenseSettings::getUrl() => LicenseSettings::getNavigationLabel()];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('License'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Short name'))
                            ->helperText(__('Shown next to the photo, e.g. "CC BY 4.0".'))
                            ->required()
                            ->maxLength(50),
                        TextInput::make('title')
                            ->label(__('Full name'))
                            ->helperText(__('Shown when pointing at the short name.'))
                            ->maxLength(150),
                        TextInput::make('url')
                            ->label(__('Link to the license text'))
                            ->url()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label(__('Description'))
                            ->helperText(__('What others may do with a photo under this license, in plain words. Shown to people choosing a license.'))
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        TextInput::make('sort_order')
                            ->label(__('Position'))
                            ->helperText(__('Licenses are listed from the lowest number.'))
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('Active'))
                            ->helperText(fn (?License $record): string => $record?->isDefault()
                                ? __('This is the default license, so it stays active. Choose another default first.')
                                : __('Inactive licenses cannot be chosen any more; photos that have one keep it.'))
                            ->disabled(fn (?License $record): bool => (bool) $record?->isDefault())
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('photos'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Short name'))
                    ->formatStateUsing(fn (License $record): string => $record->displayName())
                    ->description(fn (License $record): ?string => $record->displayDescription())
                    ->tooltip(fn (License $record): ?string => $record->displayTitle())
                    ->wrap()
                    ->badge(fn (License $record): bool => $record->isDefault())
                    ->searchable(),
                TextColumn::make('url')
                    ->label(__('Link'))
                    ->url(fn (License $record): ?string => $record->url, shouldOpenInNewTab: true)
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('photos_count')
                    ->label(__('Photos'))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label(__('Position'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                self::deleteAction()->iconButton(),
            ])
            ->tap(fn (Table $table) => EmptyState::apply(
                $table,
                __('No licenses yet'),
                __('Add the licenses people can give their photos.'),
                Heroicon::OutlinedScale,
                [CreateAction::make()->url(self::getUrl('create'))],
            ));
    }

    /** Hidden for the default license and for licenses photos use (including photos in the trash). */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (License $record): bool => $record->isDefault() || $record->photos()->exists());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLicenses::route('/'),
            'create' => CreateLicense::route('/create'),
            'edit' => EditLicense::route('/{record}/edit'),
        ];
    }
}
