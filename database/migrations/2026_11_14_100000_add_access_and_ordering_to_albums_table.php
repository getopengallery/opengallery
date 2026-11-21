<?php

use App\Models\Album;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            // How photos are ordered in the album (App\Enums\PhotoOrder).
            $table->string('photo_order', 20)->default('manual');
            // Optional password for visitors without direct access (hashed).
            $table->string('password')->nullable();
            // After this, only owner, staff and direct shares can open the album.
            $table->timestamp('access_expires_at')->nullable();
            // Materialized tree position for listing albums under their parents.
            $table->string('tree_path', 1024)->nullable()->index();
        });

        // Backfill tree paths, parents first.
        $albums = DB::table('albums')->get(['id', 'parent_id', 'title', 'sort_order'])->keyBy('id');
        $paths = [];
        $path = function (int $id) use (&$path, &$paths, $albums): string {
            if (isset($paths[$id])) {
                return $paths[$id];
            }
            $album = $albums[$id];
            $parent = $album->parent_id && isset($albums[$album->parent_id]) ? $path($album->parent_id) : '';

            return $paths[$id] = $parent.Album::treeSegment((int) $album->sort_order, (string) $album->title, (int) $album->id);
        };
        foreach ($albums as $album) {
            DB::table('albums')->where('id', $album->id)->update(['tree_path' => $path($album->id)]);
        }
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex(['tree_path']);
            $table->dropColumn(['photo_order', 'password', 'access_expires_at', 'tree_path']);
        });
    }
};
