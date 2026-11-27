<?php

namespace App\Observers;

use App\Models\Photo;
use App\Support\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs creation, changes and deletion of albums, users, groups, roles,
 * pages, photos and licenses.
 * Relationship changes come from the custom pivot models (App\Models\Pivots).
 */
class ActivityObserver
{
    public function created(Model $model): void
    {
        // Uploads are logged once per batch (ActivityLog::photosUploaded), not per photo.
        if ($model instanceof Photo) {
            return;
        }

        ActivityLog::created($model);
    }

    public function updated(Model $model): void
    {
        ActivityLog::updated($model);
    }

    public function deleted(Model $model): void
    {
        ActivityLog::deleted($model);
    }

    public function restored(Model $model): void
    {
        ActivityLog::restored($model);
    }
}
