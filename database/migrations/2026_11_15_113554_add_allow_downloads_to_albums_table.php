<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-album switch that lets the album's visitors download the original
 * photos (App\Support\AlbumAccess::canDownload()). Off for every album.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->boolean('allow_downloads')->default(false)->after('access_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('allow_downloads');
        });
    }
};
