<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An album's photos in their own order (PhotoOrder::Manual) are read page by
 * page in album_photo.sort_order. With this index the database reads them in
 * that order and stops after the page, instead of sorting the whole album for
 * every page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('album_photo', function (Blueprint $table) {
            $table->index(['album_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('album_photo', function (Blueprint $table) {
            $table->dropIndex(['album_id', 'sort_order']);
        });
    }
};
