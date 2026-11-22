<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** A tag on an album; its photos get the tag too when they are read. */
class AlbumTag extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'album_tag';
}
