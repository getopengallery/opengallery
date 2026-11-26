<?php

namespace App\Filament\Resources\Photos;

use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\Photos\Pages\EditPhoto;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Photos\Pages\ListTrashedPhotos;
use App\Filament\Resources\Photos\Pages\ViewPhoto;
use App\Filament\Resources\Photos\Schemas\PhotoForm;
use App\Filament\Resources\Photos\Schemas\PhotoInfolist;
use App\Filament\Resources\Photos\Tables\PhotosTable;
use App\Models\Photo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Gallery → Media: every photo the signed-in staff member may see.
 * Uploading happens in an album (Albums → Edit → Upload photos).
 */
class PhotoResource extends Resource
{
    protected static ?string $model = Photo::class;

    protected static ?string $slug = 'media';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Gallery;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return __('photo');
    }

    public static function getPluralModelLabel(): string
    {
        return __('media');
    }

    public static function getNavigationLabel(): string
    {
        return __('Media');
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'description', 'media.file_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Photo $record */
        return $record->title ?: ($record->original()?->file_name ?? __('Untitled photo'));
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Photo $record */
        return array_filter([
            __('In') => implode(', ', $record->albumPaths()) ?: null,
            __('Status') => $record->trashed() ? __('In the trash') : null,
        ]);
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        /** @var Photo $record */
        return self::detailUrl($record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PhotoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PhotoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhotosTable::configure($table);
    }

    /** Only photos the current user may see, including the trash (the list and trash pages each keep their half). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->visibleTo(auth()->user())
            ->with(['media', 'albums.parent', 'uploader']);
    }

    /** A trashed photo opens only for people who may restore it, as MediaController serves its files. */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->visibleTo($user)
            ->where(fn (Builder $query) => $query->whereNull('photos.deleted_at')->orWhere(fn (Builder $trashed) => $trashed->trashableBy($user)));
    }

    /** The trash lists only photos the person may restore or delete (PhotoPolicy::delete()). */
    public static function modifyTrashQuery(Builder $query): void
    {
        $query->trashableBy(auth()->user());
    }

    /**
     * Whether the photo page opens editable: for people who may edit the
     * photo, unless it is in the trash (restored first, then changed).
     */
    public static function opensForEditing(Photo $photo): bool
    {
        return ! $photo->trashed() && (auth()->user()?->can('update', $photo) ?? false);
    }

    /** The photo page: EditPhoto for editors, otherwise the read-only ViewPhoto. */
    public static function detailUrl(Photo $photo): string
    {
        return static::getUrl(self::opensForEditing($photo) ? 'edit' : 'view', ['record' => $photo]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPhotos::route('/'),
            // Before "/{record}", which would take "trash" for a photo id.
            'trash' => ListTrashedPhotos::route('/trash'),
            'view' => ViewPhoto::route('/{record}'),
            'edit' => EditPhoto::route('/{record}/edit'),
        ];
    }
}
