<?php

namespace App\Filament\Resources\Albums;

use App\Enums\Permission;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\Pages\ListTrashedAlbums;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Albums\Schemas\AlbumForm;
use App\Filament\Resources\Albums\Tables\AlbumsTable;
use App\Models\Album;
use App\Support\AlbumCollaboration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class AlbumResource extends Resource
{
    protected static ?string $model = Album::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Gallery;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return __('album');
    }

    public static function getPluralModelLabel(): string
    {
        return __('albums');
    }

    public static function getNavigationLabel(): string
    {
        return __('Albums');
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'description', 'slug'];
    }

    /** Search finds only albums the user may open for editing, also through a collaborator role. */
    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        $user = auth()->user();

        $query->with('parent.parent.parent.parent.parent');

        if (! $user->can(Permission::EditAnyAlbum->value)) {
            $query->where(fn (Builder $mine) => $mine
                ->where('owner_id', $user->id)
                ->orWhereIntegerInRaw('albums.id', AlbumCollaboration::albumIdsFor($user)));
        }
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Album $record */
        return $record->path();
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Album $record */
        return [__('Visibility') => $record->visibility->getLabel()];
    }

    public static function form(Schema $schema): Schema
    {
        return AlbumForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlbumsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlbums::route('/'),
            'create' => CreateAlbum::route('/create'),
            'trash' => ListTrashedAlbums::route('/trash'),
            'edit' => EditAlbum::route('/{record}/edit'),
        ];
    }

    /** The list, edit links and search only reach albums the person manages or works on. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->workableBy(auth()->user());
    }

    /**
     * The edit page also opens for collaborators. It offers them what their
     * role allows; AlbumPolicy::update stays closed to them.
     */
    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::getAuthorizationResponse('workOn', $record);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
