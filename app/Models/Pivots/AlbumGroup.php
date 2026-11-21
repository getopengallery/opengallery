<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AlbumGroup extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'album_group';
}
