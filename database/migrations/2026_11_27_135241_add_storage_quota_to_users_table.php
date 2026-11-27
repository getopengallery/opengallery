<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many bytes of photos each person may store (App\Support\StorageQuota):
 * their own limit, or empty for the site default; or no limit at all, which
 * is what every account that exists already gets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_quota_bytes')->nullable()->after('last_login_at');
            $table->boolean('storage_unlimited')->default(false)->after('storage_quota_bytes');
        });

        DB::table('users')->update(['storage_unlimited' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['storage_quota_bytes', 'storage_unlimited']);
        });
    }
};
