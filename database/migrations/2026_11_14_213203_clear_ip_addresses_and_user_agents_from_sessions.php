<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions no longer record IP addresses and user agents
 * (App\Support\PrivateDatabaseSessionHandler); clear what older versions stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->update(['ip_address' => null, 'user_agent' => null]);
        }
    }

    public function down(): void
    {
        // The removed values cannot be restored.
    }
};
