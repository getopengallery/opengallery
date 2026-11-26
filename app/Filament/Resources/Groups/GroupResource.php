<?php

namespace App\Filament\Resources\Groups;

use App\Enums\Permission;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Filament\Tables\Columns\UserStackColumn;
use App\Filament\Tables\EmptyState;
use App\Models\Group;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::UsersAndAccess;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('groups');
    }

    public static function getNavigationLabel(): string
    {
        return __('Groups');
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug', 'description'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Group $record */
        return array_filter([__('Description') => Str::limit((string) $record->description, 80) ?: null]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageGroups->value) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Group'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        TextInput::make('slug')
                            ->helperText(__('Leave empty to generate from the name.'))
                            ->unique(ignoreRecord: true),
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            // Only the first members are loaded per group, however large it is; the rest are counted.
            ->modifyQueryUsing(fn ($query) => UserStackColumn::withUsers($query))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                UserStackColumn::make('members')
                    ->label(__('Members')),
                TextColumn::make('users_count')
                    ->label(__('Count'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                // Users are searched, never preloaded: there may be many.
                SelectFilter::make('users')
                    ->label(__('Member'))
                    ->relationship('users', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->tap(fn (Table $table) => EmptyState::apply(
                $table,
                __('No groups yet'),
                __('Groups let you share restricted albums with several people at once.'),
                Heroicon::OutlinedUserGroup,
                [CreateAction::make()->url(self::getUrl('create'))->visible(fn (): bool => self::canCreate())],
            ));
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            'create' => CreateGroup::route('/create'),
            'edit' => EditGroup::route('/{record}/edit'),
        ];
    }
}
