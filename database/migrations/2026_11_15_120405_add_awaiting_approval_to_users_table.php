<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts now need a confirmed e-mail address (while e-mail works) and,
 * if the site asks for it, an administrator's approval.
 *
 *  - `awaiting_approval`: set for people who sign up themselves while
 *    "New accounts need approval" is on; everyone else stays false.
 *  - Accounts from before this version count as confirmed, so nobody is
 *    locked out by the upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('awaiting_approval')->default(false)->after('email_verified_at');
        });

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('awaiting_approval');
        });
    }
};
