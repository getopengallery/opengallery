<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person's favourite photos (their private "Favorites"). Kept apart from
 * album_photo on purpose: liking someone else's photo must never give rights
 * to it. Deleting the photo or the person removes the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'photo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_favorites');
    }
};
