<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

class RolePermission extends Pivot
{
    use RecordsPivotActivity;

    protected $table = 'role_has_permissions';
}
