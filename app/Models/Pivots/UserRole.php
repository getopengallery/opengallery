<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

class UserRole extends MorphPivot
{
    use RecordsPivotActivity;

    protected $table = 'model_has_roles';
}
