<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photos can be in several albums: replace photos.album_id with a pivot
 * table, keeping every photo in the album it was in.
 *
 * Data is read before and written after the schema changes, so no rows are
 * lost to cascading deletes while SQLite rebuilds a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $links = DB::table('photos')->whereNotNull('album_id')->orderBy('id')
            ->get(['id', 'album_id', 'sort_order', 'created_at']);

        Schema::table('photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('album_id');
            $table->dropColumn('sort_order');
        });

        Schema::create('album_photo', function (Blueprint $table) {
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['album_id', 'photo_id']);
            $table->index('photo_id');
        });

        foreach ($links->chunk(500) as $chunk) {
            DB::table('album_photo')->insert($chunk->map(fn ($photo) => [
                'album_id' => $photo->album_id,
                'photo_id' => $photo->id,
                'sort_order' => $photo->sort_order,
                'created_at' => $photo->created_at ?? now(),
            ])->all());
        }
    }

    public function down(): void
    {
        // A photo can only go back into one album: keep its first.
        $links = DB::table('album_photo')->orderBy('photo_id')->orderBy('created_at')->get()
            ->unique('photo_id');

        Schema::dropIfExists('album_photo');

        Schema::table('photos', function (Blueprint $table) {
            $table->foreignId('album_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
        });

        foreach ($links as $link) {
            DB::table('photos')->where('id', $link->photo_id)
                ->update(['album_id' => $link->album_id, 'sort_order' => $link->sort_order]);
        }
    }
};
