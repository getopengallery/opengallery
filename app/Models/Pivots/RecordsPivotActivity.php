<?php

namespace App\Models\Pivots;

use App\Support\ActivityLog;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot rows are only saved/deleted as models (and so fire these events)
 * when the relationship uses a custom pivot class via ->using(...).
 * sync(), attach() and detach() then report every added / removed link.
 */
trait RecordsPivotActivity
{
    protected static function bootRecordsPivotActivity(): void
    {
        static::created(fn (Pivot $pivot) => ActivityLog::pivotChanged($pivot, added: true));
        static::deleted(fn (Pivot $pivot) => ActivityLog::pivotChanged($pivot, added: false));
    }
}
