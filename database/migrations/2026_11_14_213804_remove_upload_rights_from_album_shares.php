<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Album shares only grant viewing. The `can_upload` flags were never settable
 * in the admin; uploading needs "upload photos" plus the right to edit the album.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['album_user', 'album_group'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('can_upload'));
        }
    }

    public function down(): void
    {
        foreach (['album_user', 'album_group'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->boolean('can_upload')->default(false));
        }
    }
};
