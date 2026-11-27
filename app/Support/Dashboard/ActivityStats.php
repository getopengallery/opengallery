<?php

namespace App\Support\Dashboard;

use App\Enums\Permission;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The newest activity log entries for the dashboard's "Recent activity".
 * Site-wide figures and charts live in App\Support\Statistics.
 */
class ActivityStats
{
    /**
     * The latest entries $viewer may know about: without "view any album",
     * entries about albums or photos they cannot list are left out (the
     * activity log itself stays complete).
     *
     * @return Collection<int, ActivityEvent>
     */
    public static function recent(User $viewer, int $limit = 12): Collection
    {
        return ActivityEvent::query()
            ->unless($viewer->can(Permission::ViewAnyAlbum->value), function (Builder $query) use ($viewer) {
                $albums = Album::query()->withTrashed()->listableFor($viewer)->select('albums.id');
                $photos = Photo::query()->withTrashed()->visibleTo($viewer)->select('photos.id');
                $albumType = (new Album)->getMorphClass();
                $photoType = (new Photo)->getMorphClass();

                $query->where(fn (Builder $q) => $q->whereNull('album_id')->orWhereIn('album_id', $albums))
                    ->where(fn (Builder $q) => $q
                        ->whereNull('subject_type')
                        ->orWhereNotIn('subject_type', [$albumType, $photoType])
                        ->orWhere(fn (Builder $album) => $album->where('subject_type', $albumType)->whereIn('subject_id', $albums))
                        ->orWhere(fn (Builder $photo) => $photo->where('subject_type', $photoType)->whereIn('subject_id', $photos)));
            })
            ->with(['user', 'album', 'subject'])
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
