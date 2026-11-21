<?php

namespace App\Filament\Resources\Albums\Schemas;

use App\Enums\Permission;
use App\Models\Album;
use App\Models\Photo;
use App\Support\AlbumDeletion;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Text;

/**
 * The confirmations for albums: moving them to the trash (trashDescription(),
 * says what goes with them) and deleting them permanently (schema(): says how
 * many photos are in no other album, which move to "Unsorted uploads", and
 * offers to delete them instead, see AlbumDeletion::forceDelete()).
 */
class AlbumDeletionForm
{
    /**
     * What moving these albums to the trash takes with it: the albums inside
     * them, and photos in no other album, which are hidden on the site until
     * the albums come back.
     *
     * @param  list<int>  $albumIds  the albums being moved to the trash
     */
    public static function trashDescription(array $albumIds): string
    {
        $single = count($albumIds) === 1;
        $inside = Album::query()->whereKey($albumIds)->get()
            ->flatMap(fn (Album $album) => $album->descendants()->pluck('id'))
            ->diff($albumIds)
            ->unique()
            ->values();
        $hidden = Photo::query()->whereKey(AlbumDeletion::photosOnlyIn([...$albumIds, ...$inside]))->count();

        return collect([
            match (true) {
                $inside->isEmpty() => null,
                $single => trans_choice('The :count album inside it goes to the trash too, and comes back when you restore this one.|The :count albums inside it go to the trash too, and come back when you restore this one.', $inside->count()),
                default => __('Albums inside them go to the trash too, and come back when you restore them.'),
            },
            match (true) {
                $hidden === 0 => null,
                $single => trans_choice(':count photo is in no other album. It is hidden on the site until you restore the album.|:count photos are in no other album. They are hidden on the site until you restore the album.', $hidden),
                default => trans_choice(':count photo is in no other album. It is hidden on the site until you restore the albums.|:count photos are in no other album. They are hidden on the site until you restore the albums.', $hidden),
            },
            $single ? __('You can restore it from the trash.') : __('You can restore them from the trash.'),
        ])->filter()->implode(' ');
    }

    /**
     * @param  list<int>  $albumIds  the albums being deleted
     * @return array<int, Text|Checkbox>
     */
    public static function schema(array $albumIds): array
    {
        $count = count(AlbumDeletion::photosOnlyIn($albumIds));

        if ($count === 0) {
            return [];
        }

        $moved = Text::make(trans_choice(':count photo is in no other album. It moves to "Unsorted uploads".|:count photos are in no other album. They move to "Unsorted uploads".', $count));

        if (! self::mayDeletePhotos($albumIds)) {
            return [$moved];
        }

        return [
            $moved,
            Checkbox::make('delete_photos')
                ->label(trans_choice('Delete this photo permanently instead|Delete these photos permanently instead', $count))
                ->helperText(__('Photos used on a page are always kept.')),
        ];
    }

    /**
     * Someone deleting a sub-album they created as a collaborator removes the
     * album, never the photos in it: those may be other people's.
     *
     * @param  list<int>  $albumIds
     */
    private static function mayDeletePhotos(array $albumIds): bool
    {
        $user = auth()->user();

        return $user->can(Permission::DeleteAnyAlbum->value)
            || Album::withTrashed()->whereKey($albumIds)->get()->every(fn (Album $album) => $user->can('update', $album));
    }

    /** @param  array<string, mixed>  $data  the form state */
    public static function delete(Album $album, array $data): bool
    {
        $deletePhotos = (bool) ($data['delete_photos'] ?? false) && self::mayDeletePhotos([$album->id]);

        AlbumDeletion::forceDelete($album, $deletePhotos, auth()->user());

        return true;
    }
}
