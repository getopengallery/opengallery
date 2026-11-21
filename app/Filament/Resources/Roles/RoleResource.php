<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Permission;
use App\Enums\Role as BuiltInRole;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RelationManagers\HoldersRelationManager;
use App\Filament\Tables\Columns\UserStackColumn;
use App\Filament\Tables\EmptyState;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::UsersAndAccess;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('roles');
    }

    public static function getNavigationLabel(): string
    {
        return __('Roles');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageRoles->value) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Role'))
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Role $record) => self::isBuiltIn($record))
                            ->helperText(fn (?Role $record) => self::isBuiltIn($record) ? __('Built-in roles keep their name.') : null),
                        TextInput::make('guard_name')
                            ->default('web')
                            ->hidden(),
                    ]),
                ...self::permissionSections(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Only the first users per role are loaded, however many hold it.
            ->modifyQueryUsing(fn ($query) => UserStackColumn::withUsers($query))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label(__('Permissions'))
                    ->formatStateUsing(fn (int $state, Role $record) => self::isSuperAdmin($record) ? __('All') : $state),
                UserStackColumn::make('holders')
                    ->label(__('Users'))
                    ->emptyText(__('No users')),
                TextColumn::make('users_count')
                    ->label(__('Count'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Users are searched, never preloaded: there may be many.
                SelectFilter::make('users')
                    ->label(__('Held by'))
                    ->relationship('users', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()
                    ->iconButton()
                    ->hidden(fn (Role $record) => self::isBuiltIn($record)),
            ])
            ->tap(fn (Table $table) => EmptyState::apply($table, __('No roles yet'), null, Heroicon::OutlinedShieldCheck));
    }

    public static function getRelations(): array
    {
        return [
            HoldersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * One section per area (Permission::grouped()), plus "Other" for
     * permissions added outside the enum (e.g. by plugins). Every section
     * saves only its own permissions, so they can be edited independently.
     *
     * @return list<Group|Callout>
     */
    private static function permissionSections(): array
    {
        $ids = PermissionModel::query()->pluck('id', 'name');
        $groups = collect(Permission::grouped())
            ->map(fn (array $permissions) => collect($permissions)
                ->filter(fn (Permission $permission) => $ids->has($permission->value))
                ->mapWithKeys(fn (Permission $permission) => [$ids[$permission->value] => $permission]));

        $known = $groups->flatten()->map(fn (Permission $permission) => $permission->value);
        $other = $ids->except($known->all());

        $sections = $groups->filter->isNotEmpty()->map(fn ($permissions, string $title) => self::permissionSection(
            $title,
            $permissions->map(fn (Permission $permission) => $permission->label())->all(),
            $permissions->map(fn (Permission $permission) => $permission->description())->filter()->all(),
        ))->values();

        if ($other->isNotEmpty()) {
            $sections->push(self::permissionSection('Other', $other->flip()->map(fn (string $name) => ucfirst($name))->all(), []));
        }

        return [
            Callout::make(__('Super administrators have every permission; theirs cannot be changed.'))
                ->info()
                ->columnSpanFull()
                ->visible(fn (?Role $record) => self::isSuperAdmin($record)),
            // Two balanced columns on wide screens (masonry, see theme-styles), one below.
            Group::make($sections->all())
                ->extraAttributes(['class' => 'og-perm-columns']),
        ];
    }

    /**
     * @param  array<int, string>  $options  permission id => label
     * @param  array<int, string>  $descriptions
     */
    private static function permissionSection(string $title, array $options, array $descriptions): Section
    {
        $ids = array_keys($options);

        // $title is the English group name: translated for display, stable for the state path.
        return Section::make(__($title))
            ->compact()
            ->hidden(fn (?Role $record) => self::isSuperAdmin($record))
            ->schema([
                CheckboxList::make('permission_groups.'.str($title)->slug('_'))
                    ->label(__($title))
                    ->hiddenLabel()
                    ->options($options)
                    ->descriptions($descriptions)
                    ->bulkToggleable()
                    ->afterStateHydrated(fn (CheckboxList $component, ?Role $record) => $component->state(
                        $record ? $record->permissions()->whereKey($ids)->pluck('permissions.id')->all() : [],
                    ))
                    ->dehydrated(false)
                    ->saveRelationshipsUsing(function (Role $record, ?array $state) use ($ids): void {
                        $chosen = array_values(array_intersect(array_map('intval', $state ?? []), $ids));
                        $record->permissions()->detach(array_diff($ids, $chosen));
                        $record->permissions()->syncWithoutDetaching($chosen);
                        app(PermissionRegistrar::class)->forgetCachedPermissions();
                    }),
            ]);
    }

    private static function isSuperAdmin(?Role $record): bool
    {
        return $record?->name === BuiltInRole::SuperAdmin->value;
    }

    private static function isBuiltIn(?Role $record): bool
    {
        return $record !== null && BuiltInRole::tryFrom($record->name) !== null;
    }
}
