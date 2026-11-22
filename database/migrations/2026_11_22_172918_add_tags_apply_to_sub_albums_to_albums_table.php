<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An album can pass its tags on to the albums below it. Like tags on photos,
 * they are worked out when read and never copied (App\Support\Tagging).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->boolean('tags_apply_to_sub_albums')->default(false)->after('allow_downloads');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('tags_apply_to_sub_albums');
        });
    }
};
