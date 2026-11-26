<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People without an account a restricted album is shared with by e-mail.
 * Each has a personal link (only its hash is stored) and, while they sign in,
 * a short-lived 6-digit code (hashed) with a count of wrong tries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('album_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->string('code')->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['album_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_guests');
    }
};
