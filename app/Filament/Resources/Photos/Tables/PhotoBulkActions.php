<?php

namespace App\Filament\Resources\Photos\Tables;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Forms\Components\TagPicker;
use App\Http\Controllers\PhotoSelectionDownloadController;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\AlbumAccess;
use App\Support\PhotoRenaming;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Spatie\Permission\Models\Permission as PermissionModel;

/**
 * Bulk actions of the media library besides adding to and removing from one
 * album and the license (PhotosTable): tags, uploader, leaving every album,
 * zip download and renaming by pattern. Each acts only on the selected
 * photos the user may change and says how many were left out. Filament runs
 * bulk actions inside one activity-log batch, so field changes are counted
 * with ActivityLog::changedInBulk().
 */
class PhotoBulkActions
{
    /** @return list<BulkAction> */
    public static function all(): array
    {
        return [
            self::addTags(),
            self::removeTags(),
            self::rename(),
            self::changeUploader(),
            self::removeFromAllAlbums(),
            self::download(),
        ];
    }

    public static function addTags(): BulkAction
    {
        return BulkAction::make('addTags')
            ->label(__('Add tags'))
            ->icon(Heroicon::OutlinedTag)
            ->visible(fn (): bool => SiteSettings::taggingEnabled())
            ->modalDescription(__('The photos keep the tags they have.'))
            ->schema([
                TagPicker::make('tags')
                    ->dehydrated()
                    ->saveRelationshipsUsing(null)
                    ->helperText(null)
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $user = auth()->user();
                $editable = self::editable($records);
                $full = 0;

                foreach ($editable as $photo) {
                    $full += Tagging::add($photo, (array) $data['tags'], $user) ? 0 : 1;
                }

                self::notify(
                    trans_choice('Tags added to :count photo|Tags added to :count photos', $editable->count()),
                    $records->count() - $editable->count(),
                    $full > 0 ? trans_choice(':count photo already had the most tags allowed, so not every tag was added.|:count photos already had the most tags allowed, so not every tag was added.', $full) : null,
                );
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function removeTags(): BulkAction
    {
        return BulkAction::make('removeTags')
            ->label(__('Remove tags'))
            ->icon(Heroicon::OutlinedMinusCircle)
            ->visible(fn (): bool => SiteSettings::taggingEnabled())
            ->modalDescription(__('Tags the photos get from their albums stay.'))
            ->schema([
                Select::make('tags')
                    ->label(__('Tags'))
                    ->multiple()
                    ->searchable()
                    // Only tags of photos the user can see.
                    ->options(fn (): array => Tag::query()
                        ->whereHas('photos', fn (Builder $query) => $query->visibleTo(auth()->user()))
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->all())
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $editable = self::editable($records);
                $changed = $editable->filter(fn (Photo $photo): bool => Tagging::remove($photo, (array) $data['tags']));

                self::notify(
                    trans_choice('Tags removed from :count photo|Tags removed from :count photos', $changed->count()),
                    $records->count() - $editable->count(),
                );
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function rename(): BulkAction
    {
        return BulkAction::make('rename')
            ->label(__('Rename'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalDescription(__('Gives the photos new titles from a pattern. Photos are numbered in the order they were taken. The stored files keep their names.'))
            ->schema([
                TextInput::make('pattern')
                    ->label(__('Pattern'))
                    ->default('{album} {n}')
                    ->required()
                    ->maxLength(PhotoRenaming::MAX_LENGTH)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (($problem = PhotoRenaming::problemWith((string) $value)) !== null) {
                            $fail($problem);
                        }
                    })
                    ->helperText(fn (): string => collect(PhotoRenaming::tokens())
                        ->map(fn (string $meaning, string $token): string => "{$token} {$meaning}")
                        ->implode(' · ')),
                Grid::make(2)->schema([
                    TextInput::make('start')
                        ->label(__('Start counting at'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(999999)
                        ->default(1)
                        ->required(),
                    TextInput::make('digits')
                        ->label(__('Digits'))
                        ->helperText(__('3 digits count 001, 002, 003 …'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(6)
                        ->default(3)
                        ->required(),
                ]),
            ])
            ->action(function (Collection $records, array $data): void {
                $editable = self::editable($records);
                $titles = PhotoRenaming::titles($editable, (string) $data['pattern'], (int) $data['start'], (int) $data['digits']);

                foreach ($editable as $photo) {
                    if ($photo->update(['title' => $titles[$photo->id] ?: null]) && $photo->wasChanged('title')) {
                        ActivityLog::changedInBulk($photo);
                    }
                }

                self::notify(
                    trans_choice(':count photo renamed|:count photos renamed', $editable->count()),
                    $records->count() - $editable->count(),
                );
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Makes someone else the uploader, who may then edit and trash the
     * photos. Staff with "edit any album" only, to people who may upload.
     */
    public static function changeUploader(): BulkAction
    {
        return BulkAction::make('changeUploader')
            ->label(__('Change uploader'))
            ->icon(Heroicon::OutlinedUserCircle)
            ->visible(fn (): bool => auth()->user()->can(Permission::EditAnyAlbum->value))
            ->modalDescription(__('The new uploader can edit the photos and move them to the trash. Photos in the previous uploader\'s "Unsorted uploads" move to the new uploader\'s.'))
            ->schema([
                Select::make('uploader_id')
                    ->label(__('New uploader'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::uploaders()
                        ->where(fn (Builder $query) => PhotoFilters::whereContains(
                            PhotoFilters::whereContains($query, 'name', $search),
                            'email',
                            $search,
                            'or',
                        ))
                        ->orderBy('name')
                        ->limit(20)
                        ->pluck('name', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => User::find($value)?->name)
                    ->required(),
            ])
            ->action(fn (Collection $records, array $data) => self::changeUploaderTo($records, (int) $data['uploader_id']))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Takes the photos out of every album. Since every photo stays in one
     * album, they move to their uploader's "Unsorted uploads" (the current
     * user's when the uploader is gone). Needs the right to trash each photo.
     */
    public static function removeFromAllAlbums(): BulkAction
    {
        return BulkAction::make('removeFromAllAlbums')
            ->label(__('Remove from all albums'))
            ->icon(Heroicon::OutlinedRectangleStack)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('The photos leave every album they are in and move to their uploader\'s "Unsorted uploads", where only the uploader sees them.'))
            ->action(function (Collection $records): void {
                $user = auth()->user();
                $removable = $records->load(['albums', 'uploader'])->filter(fn (Photo $photo): bool => $user->can('delete', $photo));

                // Into the uploader's "Unsorted uploads" first, so no photo is ever in no album.
                $keep = [];

                foreach ($removable->groupBy(fn (Photo $photo) => ($photo->uploader ?? $user)->id) as $photosOfOnePerson) {
                    $inbox = Album::inbox($photosOfOnePerson->first()->uploader ?? $user);
                    $inbox->addPhotos($photosOfOnePerson->modelKeys());

                    foreach ($photosOfOnePerson as $photo) {
                        $keep[$photo->id] = $inbox->id;
                    }
                }

                // Then out of every other album, logged once per album. Covers move on through the pivot.
                $byAlbum = [];

                foreach ($removable as $photo) {
                    foreach ($photo->albums as $album) {
                        if ($album->id !== $keep[$photo->id]) {
                            $byAlbum[$album->id] ??= ['album' => $album, 'photos' => []];
                            $byAlbum[$album->id]['photos'][] = $photo->id;
                        }
                    }
                }

                foreach ($byAlbum as ['album' => $album, 'photos' => $photoIds]) {
                    $album->photos()->detach($photoIds);
                    ActivityLog::photosRemoved($album, count($photoIds));
                }

                self::notify(
                    trans_choice(':count photo removed from all albums|:count photos removed from all albums', $removable->count()),
                    $records->count() - $removable->count(),
                    leftOut: trans_choice(':count photo was left out because you cannot move it to the trash.|:count photos were left out because you cannot move them to the trash.', $records->count() - $removable->count()),
                );
            })
            ->deselectRecordsAfterCompletion();
    }

    /** The original files of the selected photos as one zip, named after their titles. */
    public static function download(): BulkAction
    {
        return BulkAction::make('download')
            ->label(__('Download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (Collection $records, Component $livewire): void {
                $user = auth()->user();
                $records->load(['albums']);
                $downloadable = $records->filter(fn (Photo $photo): bool => AlbumAccess::canDownload($user, $photo));
                $refused = $records->count() - $downloadable->count();

                if ($refused > 0) {
                    Notification::make()
                        ->title(trans_choice(':count photo was left out because you cannot download it.|:count photos were left out because you cannot download them.', $refused))
                        ->warning()
                        ->send();
                }

                if ($downloadable->isNotEmpty()) {
                    $livewire->redirect(route('media.selection.download', PhotoSelectionDownloadController::prepare($downloadable->modelKeys())));
                }
            });
    }

    /** @param Collection<int, Photo> $photos */
    private static function changeUploaderTo(Collection $photos, int $uploaderId): void
    {
        abort_unless(auth()->user()->can(Permission::EditAnyAlbum->value), 403);
        $uploader = self::uploaders()->find($uploaderId);
        abort_unless($uploader instanceof User, 403);

        $editable = self::editable($photos);

        foreach ($editable as $photo) {
            $previous = $photo->uploader_id;

            if (! $photo->update(['uploader_id' => $uploader->id]) || ! $photo->wasChanged('uploader_id')) {
                continue;
            }

            ActivityLog::changedInBulk($photo);

            // The previous uploader's private "Unsorted uploads" is no place for someone else's photo.
            $oldInbox = $photo->albums->first(fn (Album $album) => $album->system_album === Album::INBOX && $album->owner_id === $previous);

            if ($oldInbox) {
                Album::inbox($uploader)->addPhotos($photo);
                $oldInbox->photos()->detach($photo->id);
            }
        }

        self::notify(
            trans_choice(':count photo now belongs to :name|:count photos now belong to :name', $editable->count(), ['name' => $uploader->name]),
            $photos->count() - $editable->count(),
        );
    }

    /**
     * People who may upload photos (and so own them): holders of "upload
     * photos" and super administrators.
     *
     * @return Builder<User>
     */
    private static function uploaders(): Builder
    {
        $exists = PermissionModel::query()->where('name', Permission::UploadPhotos->value)->exists();

        return User::query()->where(fn (Builder $query) => $query
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', Role::SuperAdmin->value))
            ->when($exists, fn (Builder $query) => $query->orWhere(fn (Builder $holders) => $holders->permission(Permission::UploadPhotos->value))));
    }

    /**
     * The photos the user may edit.
     *
     * @param  Collection<int, Photo>  $photos
     * @return Collection<int, Photo>
     */
    private static function editable(Collection $photos): Collection
    {
        $user = auth()->user();

        return $photos->load('albums')->filter(fn (Photo $photo): bool => $user->can('update', $photo));
    }

    /** One message for the action: what was done, extra notes, and how many photos were left out. */
    private static function notify(string $title, int $refused, ?string $note = null, ?string $leftOut = null): void
    {
        $body = array_filter([
            $note,
            $refused > 0 ? ($leftOut ?? trans_choice(':count photo was left out because you cannot edit it.|:count photos were left out because you cannot edit them.', $refused)) : null,
        ]);

        Notification::make()
            ->title($title)
            ->body($body === [] ? null : implode(' ', $body))
            ->{$body === [] ? 'success' : 'warning'}()
            ->send();
    }
}
