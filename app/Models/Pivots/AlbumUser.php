<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AlbumUser extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'album_user';
}
