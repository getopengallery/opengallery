<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL and MariaDB index every foreign key by themselves; SQLite does not.
 * These are the foreign keys the gallery looks things up by (uploads of a
 * person, sub-albums, covers, activity of a person or album). Only on SQLite,
 * so MySQL does not get a second index on the same column.
 */
return new class extends Migration
{
    private const INDEXES = [
        'photos' => ['uploader_id'],
        'albums' => ['parent_id', 'cover_photo_id'],
        'activity_events' => ['user_id', 'album_id'],
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }
    }
};
