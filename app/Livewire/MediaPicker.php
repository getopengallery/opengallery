<?php

namespace App\Livewire;

use App\Enums\Permission;
use App\Filament\Resources\Photos\Tables\PhotoFilters;
use App\Filament\Resources\Photos\Tables\PhotosTable;
use App\Filament\Tables\FilterBar;
use App\Filament\Tables\MediaGrid;
use App\Models\Album;
use App\Models\Photo;
use App\Support\PhotoUploads;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The media pool picker: the one way to choose photos in the admin
 * (album photos and covers, page Photo / Gallery blocks, ...).
 *
 * It only selects. Photos dropped on its upload zone are uploaded in chunks
 * (App\Http\Controllers\PhotoUploadController), added to the library and
 * come back selected (addUploaded()). When the person confirms, it dispatches the browser
 * event "media-picked" with {key, items: [{id, thumb, title}]}; whoever
 * opened it (App\Filament\Actions\PickMediaAction) decides what to do.
 * Open it through PickMediaAction or the MediaPickerField form field.
 */
class MediaPicker extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    /** Identifies the opener; echoed back in "media-picked". */
    #[Locked]
    public string $key = '';

    #[Locked]
    public bool $multiple = true;

    /** Label of the confirm button; empty for the default "Use selected photos". */
    #[Locked]
    public string $confirmLabel = '';

    /** Optional album the first filter refers to. */
    #[Locked]
    public ?int $scopeAlbumId = null;

    /** "only": show only that album's photos by default; "exclude": hide them by default. */
    #[Locked]
    public ?string $scope = null;

    /** Where uploads go: "album:{id}", "inbox", "page-images", or null for no uploads. */
    #[Locked]
    public ?string $uploadTo = null;

    /**
     * Keeps the picker to this album's photos and the person's own uploads,
     * for people working through a collaborator role. They do not get the
     * library, or the album and uploader filters that would name other people.
     */
    #[Locked]
    public ?int $limitToAlbumId = null;

    /** Photos uploaded in this picker: always shown, and selected for the person. */
    public array $justUploaded = [];

    public function mount(
        string $key,
        bool $multiple = true,
        string $confirmLabel = '',
        ?int $scopeAlbumId = null,
        ?string $scope = null,
        ?string $uploadTo = null,
        ?int $limitToAlbumId = null,
    ): void {
        $user = auth()->user();
        $limit = $limitToAlbumId ? Album::find($limitToAlbumId) : null;

        abort_unless($limit ? $user?->can('workOn', $limit) : $user?->can(Permission::AccessAdmin->value), 403);

        $this->limitToAlbumId = $limit?->id;

        $this->key = $key;
        $this->multiple = $multiple;
        $this->confirmLabel = $confirmLabel;
        $this->scopeAlbumId = $scopeAlbumId;
        $this->scope = in_array($scope, ['only', 'exclude'], true) && $scopeAlbumId ? $scope : null;
        $this->uploadTo = $uploadTo;
    }

    /**
     * Settings for the browser-side uploader, or null when this person may
     * not upload here.
     *
     * @return array<string, mixed>|null see PhotoUploads::uploaderConfig()
     */
    public function uploaderConfig(): ?array
    {
        if (! PhotoUploads::allows($this->uploadTo, auth()->user())) {
            return null;
        }

        return PhotoUploads::uploaderConfig($this->uploadTo, $this->multiple);
    }

    /**
     * Keeps freshly uploaded photos in the grid whatever the album filter
     * says. Called by the uploader in the browser, so the ids are checked
     * again here; it selects the returned ids in the grid itself. Nothing
     * is redrawn, so the grid stays still during an upload (showUploaded()
     * follows at the end). Filters, page and selection stay as they are:
     * resetting the table would clear the selection. The page behind the
     * picker shows the new photos when the picker closes.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int> the ids to select
     */
    #[Renderless]
    public function addUploaded(array $ids): array
    {
        $user = auth()->user();
        $ids = Photo::query()
            ->visibleTo($user)
            ->where('uploader_id', $user->id)
            ->whereKey(array_map('intval', array_filter($ids, 'is_numeric')))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        $this->justUploaded = $this->multiple ? array_values(array_unique([...$this->justUploaded, ...$ids])) : array_slice($ids, -1);

        return $this->multiple ? $ids : array_slice($ids, -1);
    }

    /** Redraws the grid once an upload has finished, with the new photos in it. */
    public function showUploaded(): void
    {
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        // The same filter chips as the media library; the album scope toggle comes first.
        return FilterBar::configure($table, primary: ['scope', 'albums', 'uploader', 'taken'])
            ->query($this->availablePhotos()->with(['media', 'albums']))
            ->defaultSort('created_at', 'desc')
            // Smaller pages than the library: the picker sits in a modal.
            ->tap(fn (Table $table) => MediaGrid::configure($table, ['openable' => false, 'single' => ! $this->multiple], perPage: 25))
            ->filters([
                Filter::make('scope')
                    ->label($this->scope === 'only' ? __('Only photos in this album') : __('Hide photos already in this album'))
                    ->toggle()
                    ->default()
                    ->visible(fn () => $this->scope !== null)
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => ($this->scope === 'only'
                        ? $q->whereHas('albums', fn (Builder $a) => $a->whereKey($this->scopeAlbumId))
                        : $q->whereDoesntHave('albums', fn (Builder $a) => $a->whereKey($this->scopeAlbumId)))
                        // Fresh uploads stay visible whatever the album filter says.
                        ->orWhereIn('photos.id', $this->justUploaded))),
                ...($this->limitToAlbumId === null ? [PhotosTable::albumFilter(), PhotosTable::uploaderFilter()] : []),
                PhotosTable::uploadedFilter(),
                // Collaborators only get filters that name nobody else.
                ...($this->limitToAlbumId === null ? PhotoFilters::all() : []),
            ])
            ->toolbarActions([
                ...($this->multiple ? MediaGrid::selectionActions() : []),
                BulkAction::make('choose')
                    ->label($this->confirmLabel ?: __('Use selected photos'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('primary')
                    ->action(fn (Collection $records) => $this->pick($records->all()))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->maxSelectableRecords($this->multiple ? null : 1)
            ->emptyStateHeading(__('No photos match'))
            ->emptyStateDescription(__('Change the filters, or upload new photos.'));
    }

    /** @return Builder<Photo> what this person can choose from here */
    private function availablePhotos(): Builder
    {
        $user = auth()->user();

        if ($this->limitToAlbumId !== null) {
            $album = Album::find($this->limitToAlbumId);
            abort_unless($album && $user->can('workOn', $album), 403);

            return Photo::query()->where(fn (Builder $query) => $query
                ->where('photos.uploader_id', $user->id)
                ->orWhereHas('albums', fn (Builder $albums) => $albums->whereKey($album->id)));
        }

        // Albums only take photos the person may edit; pages may show any photo they can see.
        return Photo::query()->visibleTo($user)
            ->when($this->uploadTo !== 'page-images', fn (Builder $query) => $query->reusableBy($user));
    }

    /** @param list<Photo> $photos */
    public function pick(array $photos): void
    {
        $photos = $this->multiple ? $photos : array_slice($photos, 0, 1);

        $this->dispatch('media-picked',
            key: $this->key,
            items: array_map(fn (Photo $photo) => [
                'id' => $photo->id,
                'title' => $photo->title,
                'thumb' => $photo->mediaUrl('thumb'),
                'ratio' => $photo->aspectRatio(),
            ], $photos),
        );
    }

    public function render(): View
    {
        return view('livewire.media-picker');
    }
}
