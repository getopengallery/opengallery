<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

class GroupUser extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'group_user';
}
