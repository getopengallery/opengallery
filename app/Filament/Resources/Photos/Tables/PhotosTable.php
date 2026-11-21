<?php

namespace App\Filament\Resources\Photos\Tables;

use App\Enums\Permission;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Photos\Pages\Concerns\HasMediaLayout;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Tables\EmptyState;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Filament\Tables\MediaGrid;
use App\Filament\Trash\ListTrashedRecords;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Models\Photo;
use App\Support\ActivityLog;
use App\Support\AlbumCollaboration;
use App\Support\SiteSettings;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Number;
use Livewire\Component;

/**
 * Media library table, shown as a list (details in columns) or as a grid of
 * thumbnails; the layout is chosen on the page (HasMediaLayout) and
 * remembered per session. On the trash page (ListTrashedPhotos) it offers
 * only Restore and Delete permanently.
 */
class PhotosTable
{
    public static function configure(Table $table): Table
    {
        $livewire = $table->getLivewire();
        $grid = in_array(HasMediaLayout::class, class_uses_recursive($livewire), true) && $livewire->usesGridLayout();
        $trash = $livewire instanceof ListTrashedRecords;

        return $table
            ->defaultSort('created_at', 'desc')
            // In the grid, clicking a photo selects it; each tile has its own "Open" button.
            ->recordUrl($grid ? null : fn (Photo $record) => auth()->user()->can('view', $record) ? route('filament.admin.resources.media.view', $record) : null)
            ->when(
                $grid,
                fn (Table $table) => MediaGrid::configure($table),
                fn (Table $table) => $table
                    ->columns(self::listColumns($trash))
                    ->paginated([10, 25, 50, 100])
                    ->defaultPaginationPageOption(25),
            )
            ->filters([
                self::albumFilter(),
                self::uploaderFilter(),
                self::uploadedFilter(),
            ])
            ->recordActions(match (true) {
                $grid => [],
                $trash => [
                    ViewAction::make()->iconButton(),
                    TrashActions::restore(),
                    TrashActions::deletePermanently(),
                ],
                default => [
                    ViewAction::make()->iconButton(),
                    EditAction::make()->iconButton(),
                    TrashActions::moveToTrash()->iconButton(),
                ],
            })
            ->toolbarActions($grid
                ? [...MediaGrid::selectionActions(), ...self::bulkActions($trash)]
                : [BulkActionGroup::make(self::bulkActions($trash))])
            ->when(! $trash, fn (Table $table) => EmptyState::apply(
                $table,
                __('No photos yet'),
                __('Upload photos here or in an album. A photo can be in several albums.'),
                Heroicon::OutlinedPhoto,
                [Action::make('upload')
                    ->label(__('Upload photos'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->url(fn (): string => PhotoResource::getUrl('index', ['action' => 'uploadPhotos']))
                    ->visible(fn (): bool => auth()->user()->can(Permission::UploadPhotos->value))],
            ));
    }

    /** Albums the photo is in (only albums the user can list). Shared with the media picker. */
    public static function albumFilter(): SelectFilter
    {
        return SelectFilter::make('albums')
            ->label(__('Album'))
            ->relationship('albums', 'title', fn (Builder $query) => $query->listableFor(auth()->user()))
            ->searchable()
            ->preload();
    }

    /** Who uploaded the photo (searched, never preloaded: there may be many users). Shared with the media picker. */
    public static function uploaderFilter(): SelectFilter
    {
        return SelectFilter::make('uploader')
            ->label(__('Uploaded by'))
            ->relationship('uploader', 'name')
            ->searchable();
    }

    /** "Uploaded" date range. Shared with the media picker. */
    public static function uploadedFilter(): DateRangeFilter
    {
        return DateRangeFilter::make('uploaded')
            ->label(__('Uploaded'))
            ->column('photos.created_at');
    }

    /** @return list<BulkAction> */
    private static function bulkActions(bool $trash): array
    {
        if ($trash) {
            return [
                TrashActions::restoreBulk()->authorizeIndividualRecords('restore'),
                TrashActions::deletePermanentlyBulk()->authorizeIndividualRecords('forceDelete'),
            ];
        }

        return [
            BulkAction::make('addToAlbum')
                ->label(__('Add to album'))
                ->icon(Heroicon::OutlinedPlusCircle)
                ->schema([self::albumSelect(__('Add to'), 'upload')])
                ->action(fn (Collection $records, array $data) => self::addToAlbum($records, (int) $data['album_id']))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('newAlbum')
                ->label(__('New album from selection'))
                ->icon(Heroicon::OutlinedFolderPlus)
                ->visible(fn (): bool => AlbumResource::canCreate())
                ->modalHeading(__('New album from selection'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(255),
                ])
                ->action(fn (Collection $records, array $data, Component $livewire) => self::newAlbum($records, $data['title'], $livewire)),
            BulkAction::make('removeFromAlbum')
                ->label(__('Remove from album'))
                ->icon(Heroicon::OutlinedMinusCircle)
                ->schema([self::albumSelect(__('Remove from'), 'update')])
                ->action(fn (Collection $records, array $data) => self::removeFromAlbum($records, (int) $data['album_id']))
                ->deselectRecordsAfterCompletion(),
            TrashActions::moveToTrashBulk()
                ->modalDescription(__('The photos are removed from every album they are in. You can restore them from the trash.'))
                ->authorizeIndividualRecords('delete'),
        ];
    }

    /** @return list<Column> */
    private static function listColumns(bool $trash): array
    {
        return [
            self::thumbnail()
                ->imageHeight(48)
                ->imageWidth(64)
                ->extraImgAttributes(['class' => 'og-thumb', 'loading' => 'lazy']),
            TextColumn::make('title')
                ->searchable()
                ->sortable()
                ->placeholder(__('Untitled'))
                ->description(fn (Photo $record) => $record->original()?->file_name),
            TextColumn::make('albums')
                ->label(__('Albums'))
                // Albums in the trash are marked: a photo only in such albums is hidden on the site.
                ->state(fn (Photo $record) => $record->albums
                    ->map(fn (Album $album) => $album->trashed() ? __(':album (in the trash)', ['album' => $album->path()]) : $album->path())
                    ->all() ?: null)
                ->listWithLineBreaks()
                ->limitList(3)
                ->expandableLimitedList()
                ->placeholder(__('In no album'))
                ->wrap(),
            TextColumn::make('uploader.name')
                ->label(__('Uploaded by'))
                ->placeholder(__('Unknown'))
                ->sortable(),
            TextColumn::make('created_at')
                ->label(__('Uploaded'))
                ->dateTime()
                ->sortable(),
            TextColumn::make('taken_at')
                ->label(__('Taken'))
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('dimensions')
                ->label(__('Size'))
                ->state(fn (Photo $record) => $record->width && $record->height ? "{$record->width} × {$record->height}" : null)
                ->description(fn (Photo $record) => $record->original() ? Number::fileSize($record->original()->size, precision: 1) : null)
                ->placeholder('–')
                ->toggleable(),
            TextColumn::make('deleted_at')
                ->label(__('In the trash since'))
                ->dateTime()
                ->sortable()
                ->visible($trash),
        ];
    }

    private static function thumbnail(): ImageColumn
    {
        return ImageColumn::make('thumbnail')
            ->label('')
            ->state(fn (Photo $record) => $record->mediaUrl('thumb'));
    }

    private static function albumSelect(string $label, string $ability): Select
    {
        return Select::make('album_id')
            ->label($label)
            ->options(fn () => self::manageableAlbums($ability))
            ->searchable()
            ->required();
    }

    /** @param Collection<int, Photo> $photos */
    private static function addToAlbum(Collection $photos, int $albumId): void
    {
        $album = Album::findOrFail($albumId);
        abort_unless(auth()->user()->can('upload', $album), 403);

        // Adding a photo publishes it there: with only a collaborator role, own uploads only.
        $addable = AlbumCollaboration::addablePhotoIds($album, auth()->user(), $photos->modelKeys());
        $refused = $photos->count() - count($addable);

        // Through the album, like its Photos section: added at the end, logged once for the album.
        $added = $photos
            ->filter(fn (Photo $photo) => in_array($photo->id, $addable, true))
            ->reject(fn (Photo $photo) => $photo->albums->contains($album));
        $album->addPhotos($added->modelKeys());
        ActivityLog::photosAdded($album, $added->count());

        Notification::make()
            ->title(trans_choice(':count photo added to :album|:count photos added to :album', $added->count(), ['album' => $album->title]))
            ->body($refused > 0 ? trans_choice(':count photo was left out because someone else uploaded it.|:count photos were left out because someone else uploaded them.', $refused) : null)
            ->{$refused > 0 ? 'warning' : 'success'}()
            ->send();
    }

    /**
     * Creates a private album owned by the current user with the selected
     * photos (in the order shown), like the "New album" form, and opens it.
     *
     * @param  Collection<int, Photo>  $photos
     */
    private static function newAlbum(Collection $photos, string $title, Component $livewire): void
    {
        $user = auth()->user();
        abort_unless($user->can('create', Album::class), 403);

        $ids = Photo::visibleTo($user)->reusableBy($user)->whereKey($photos->modelKeys())->pluck('id')->all();
        $ids = array_values(array_filter($photos->modelKeys(), fn (int $id) => in_array($id, $ids, true)));

        $album = Album::create([
            'title' => $title,
            'owner_id' => $user->id,
            'photo_order' => SiteSettings::defaultPhotoOrder(),
        ]);
        $album->addPhotos($ids);
        ActivityLog::photosAdded($album, count($ids));

        Notification::make()
            ->title(trans_choice(':count photo added to :album|:count photos added to :album', count($ids), ['album' => $album->title]))
            ->success()
            ->send();

        $livewire->redirect(AlbumResource::getUrl('edit', ['record' => $album]));
    }

    /** @param Collection<int, Photo> $photos */
    private static function removeFromAlbum(Collection $photos, int $albumId): void
    {
        $album = Album::findOrFail($albumId);
        abort_unless(auth()->user()->can('update', $album), 403);

        self::notifyRemoved($album, $album->removePhotos($photos));
    }

    /**
     * Result of "Remove from album" (here and in an album's Photos section):
     * how many left the album, and a warning for those kept because it is their only album.
     *
     * @param  array{removed: int, kept: int}  $result  from Album::removePhotos()
     */
    public static function notifyRemoved(Album $album, array $result): void
    {
        Notification::make()
            ->title(trans_choice(':count photo removed from :album|:count photos removed from :album', $result['removed'], ['album' => $album->title]))
            ->body($result['kept'] > 0 ? trans_choice(':count photo is only in this album and was kept. Move it to the trash to remove it.|:count photos are only in this album and were kept. Move them to the trash to remove them.', $result['kept']) : null)
            ->{$result['kept'] > 0 ? 'warning' : 'success'}()
            ->send();
    }

    /**
     * Albums the current user may add photos to ("upload") or manage and so
     * remove photos from ("update").
     *
     * @return array<int, string> album paths by id
     */
    public static function manageableAlbums(string $ability = 'upload'): array
    {
        $user = auth()->user();

        return Album::query()
            ->with('parent')
            ->get()
            ->filter(fn (Album $album) => $user->can($ability, $album))
            ->mapWithKeys(fn (Album $album) => [$album->id => $album->path()])
            ->sort()
            ->all();
    }
}
