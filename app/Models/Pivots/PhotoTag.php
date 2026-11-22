<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** A tag set on the photo itself, as opposed to one it gets from an album. */
class PhotoTag extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'photo_tag';
}
