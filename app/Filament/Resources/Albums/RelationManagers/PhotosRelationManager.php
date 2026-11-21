<?php

namespace App\Filament\Resources\Albums\RelationManagers;

use App\Enums\Permission;
use App\Enums\PhotoOrder;
use App\Filament\Actions\PickMediaAction;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Photos\Tables\PhotosTable;
use App\Filament\Tables\EmptyState;
use App\Filament\Tables\MediaGrid;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Models\Photo;
use App\Support\ActivityLog;
use App\Support\AlbumCollaboration;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * The album's photos on its edit page: a grid with the same click-to-select
 * tiles as the media library. Photos are added from the library (picker
 * modal) or uploaded; they can be reordered, made the cover or removed.
 * Collaborators use the same section with what their role allows.
 */
class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Photos');
    }

    /** The photos are the main content of an album: load them with the page. */
    protected static bool $isLazy = false;

    /** Name in the edit page URL that opens one of its actions on load. */
    public const URL_IDENTIFIER = 'photos';

    /** Edit page URL that opens "Add photos" right away (used by the album page on the site). */
    public static function addPhotosUrl(Album $album): string
    {
        return AlbumResource::getUrl('edit', [
            'record' => $album,
            'tableAction' => 'addFromLibrary',
            'actionComponent' => self::URL_IDENTIFIER,
        ]);
    }

    /** Lets "?tableAction=…&actionComponent=photos" open an action of this section on page load. */
    #[Unexposed]
    public function getDefaultActionUrlIdentifier(): ?string
    {
        return self::URL_IDENTIFIER;
    }

    /** Also for collaborators, who do not have the media library. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass)
            || (auth()->user()?->can('workOn', $ownerRecord) ?? false);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->photos()->count();
    }

    /** Photos chosen in the media picker ("Add from library"). */
    public function addPickedPhotos(array $ids): void
    {
        /** @var Album $album */
        $album = $this->getOwnerRecord();
        abort_unless(auth()->user()->can('upload', $album), 403);

        $ids = Photo::visibleTo(auth()->user())->whereKey(array_map('intval', $ids))->pluck('id')->all();
        $ids = AlbumCollaboration::addablePhotoIds($album, auth()->user(), $ids);
        $before = $album->photos()->count();
        $album->addPhotos($ids);
        $added = $album->photos()->count() - $before;
        ActivityLog::photosAdded($album, $added);

        // Photos uploaded in the picker are already in the album (and logged as uploaded).
        Notification::make()
            ->title($added === 0 && $ids !== []
                ? trans_choice(':count photo is in :album|:count photos are in :album', count($ids), ['album' => $album->title])
                : trans_choice(':count photo added to :album|:count photos added to :album', $added, ['album' => $album->title]))
            ->success()
            ->send();

        $this->resetTable();
    }

    /** Nothing can change while the album is in the trash (EditAlbum is read-only then too). */
    public function isReadOnly(): bool
    {
        return $this->getOwnerRecord()->trashed() || parent::isReadOnly();
    }

    /** Dragging photos into order is logged once per album (merged while you keep dragging). */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        abort_unless($this->arrangesPhotos($this->getOwnerRecord()), 403);

        parent::reorderTable($order, $draggedRecordKey);

        ActivityLog::photosReordered($this->getOwnerRecord());
    }

    /** Another part of the page changed the album's photos (a new cover from elsewhere, photos uploaded in the picker). */
    #[On('album-photos-changed')]
    public function photosChanged(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        /** @var Album $album */
        $album = $this->getOwnerRecord();
        $editable = ! $album->trashed();
        $user = auth()->user();
        // Details open in the media library, which a role does not give access to.
        $openable = $user->can(Permission::AccessAdmin->value) && ! AlbumCollaboration::worksThroughRole($album, $user);

        return $table
            ->recordTitleAttribute('title')
            // Drag to reorder only with "Manual" order; otherwise the album's
            // chosen order (by date or name) is applied by the relationship.
            ->reorderable('sort_order', fn () => $album->photo_order === PhotoOrder::Manual && $this->arrangesPhotos($album))
            ->description(fn () => $album->photo_order === PhotoOrder::Manual || ! $this->arrangesPhotos($album) ? null : __('Sorted by: :order. Choose "Manual" under "Sort photos by" to drag photos into order.', ['order' => $album->photo_order->getLabel()]))
            ->modifyQueryUsing(fn ($query) => $query->with(['media', 'albums']))
            ->tap(fn (Table $table) => MediaGrid::configure($table, ['coverId' => $album->cover_photo_id, 'openable' => $openable]))
            ->headerActions([
                // One way in: the picker shows the library and takes new uploads,
                // which go straight into this album.
                PickMediaAction::make('addFromLibrary')
                    ->label(__('Add photos'))
                    ->color('primary')
                    ->visible(fn () => $editable && auth()->user()->can('upload', $album))
                    ->modalHeading(__('Add photos'))
                    ->pickerKey('album-photos-'.$album->id)
                    ->multiple()
                    ->confirmLabel(__('Add to album'))
                    ->scopeAlbum($album->id, 'exclude')
                    ->uploadTo('album:'.$album->id)
                    ->limitToAlbum(AlbumCollaboration::pickerAlbumId($album, $user))
                    ->onPicked('$wire.call("addPickedPhotos", ids)'),
            ])
            ->toolbarActions(! $editable ? [] : [
                ...MediaGrid::selectionActions(),
                BulkAction::make('useAsCover')
                    ->label(__('Use as cover'))
                    ->icon(Heroicon::OutlinedStar)
                    ->color('gray')
                    ->visible(fn () => $this->arrangesPhotos($album))
                    ->action(function (Collection $records) use ($album) {
                        abort_unless($this->arrangesPhotos($album), 403);

                        if ($records->count() !== 1) {
                            Notification::make()->title(__('Select exactly one photo to use as the cover.'))->warning()->send();

                            return;
                        }

                        $album->update(['cover_photo_id' => $records->first()->id]);
                        $this->dispatch('album-cover-updated');
                        Notification::make()->title(__('Cover changed'))->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('removeFromAlbum')
                    ->label(__('Remove from album'))
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('gray')
                    ->visible(fn () => auth()->user()->can('update', $album) || auth()->user()->can('removePhotos', $album))
                    ->requiresConfirmation()
                    ->modalDescription(fn () => AlbumCollaboration::worksThroughRole($album, auth()->user())
                        ? __('Photos stay in their other albums. A photo in no other album moves to the "Unsorted uploads" of the person who uploaded it.')
                        : __('The photos stay in the media library and in their other albums.'))
                    ->action(fn (Collection $records) => $this->removeFromAlbum($album, $records))
                    ->deselectRecordsAfterCompletion(),
                TrashActions::moveToTrashBulk()
                    ->modalDescription(__('The photos are removed from every album they are in. You can restore them from the trash in Media.'))
                    ->authorizeIndividualRecords('delete')
                    // A role takes photos out of this album, never out of every album.
                    ->visible(fn () => auth()->user()->can('update', $album)),
            ])
            ->tap(fn (Table $table) => EmptyState::apply(
                $table,
                __('This album has no photos yet'),
                $editable ? __('Use "Add photos" to choose photos from the library or upload new ones.') : null,
                Heroicon::OutlinedPhoto,
            ));
    }

    /** @param Collection<int, Photo> $photos */
    private function removeFromAlbum(Album $album, Collection $photos): void
    {
        $user = auth()->user();

        if ($user->can('update', $album)) {
            PhotosTable::notifyRemoved($album, $album->removePhotos($photos));

            return;
        }

        abort_unless($user->can('removePhotos', $album), 403);

        $removable = $photos->filter(fn (Photo $photo) => $user->can('removePhotos', [$album, $photo]));
        $refused = $photos->count() - $removable->count();

        PhotosTable::notifyRemoved($album, $album->removePhotos($removable, moveLastCopies: true));

        if ($refused > 0) {
            Notification::make()
                ->title(trans_choice(':count photo was left out because someone else uploaded it.|:count photos were left out because someone else uploaded them.', $refused))
                ->warning()
                ->send();
        }
    }

    /** Sorting by hand and choosing the cover are album settings: owners, staff and Editors. */
    private function arrangesPhotos(Album $album): bool
    {
        $user = auth()->user();

        return $user->can('update', $album) || $user->can('editSettings', $album);
    }
}
