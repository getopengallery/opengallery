<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People and groups invited to work on an album. Separate from the shares
 * (album_user, album_group), which only grant viewing of restricted albums.
 * MySQL and MariaDB index foreign keys by themselves; SQLite does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('album_collaborators', function (Blueprint $table) {
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['album_id', 'user_id']);

            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->index('user_id');
            }
        });

        Schema::create('album_group_collaborators', function (Blueprint $table) {
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['album_id', 'group_id']);

            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->index('group_id');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_group_collaborators');
        Schema::dropIfExists('album_collaborators');
    }
};
