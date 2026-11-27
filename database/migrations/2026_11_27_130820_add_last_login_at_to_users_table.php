<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When each person last signed in, for the statistics page (active and
 * inactive members). It is set on every login whatever the activity log
 * level, and filled once from the login entries the log still holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('awaiting_approval');
        });

        DB::table('activity_events')
            ->where('type', 'login')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(created_at) as last_login')
            ->orderBy('user_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('users')->where('id', $row->user_id)->update(['last_login_at' => $row->last_login]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
