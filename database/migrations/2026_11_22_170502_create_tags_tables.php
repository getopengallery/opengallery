<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags on albums and photos. `normalized` (lower case, single spaces) makes
 * "PET 1" and "pet  1" one tag; "pet1" stays its own tag. `search_key` holds
 * only the letters and numbers, so searching and the "similar tags" list find
 * all of them. Photos get their album's tags when they are read; those are
 * never copied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('normalized', 50)->unique();
            $table->string('search_key', 50);
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('album_tag', function (Blueprint $table) {
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->index()->constrained()->cascadeOnDelete();
            $table->primary(['album_id', 'tag_id']);
        });

        Schema::create('photo_tag', function (Blueprint $table) {
            $table->foreignId('photo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->index()->constrained()->cascadeOnDelete();
            $table->primary(['photo_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_tag');
        Schema::dropIfExists('album_tag');
        Schema::dropIfExists('tags');
    }
};
