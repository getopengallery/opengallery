<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who created an album. A sub-album made by a collaborator belongs to the
 * parent's owner, so this is what lets its creator delete it again. Sub-albums
 * made before this column existed are filled in from the activity log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
        });

        DB::table('activity_events')
            ->join('users', 'users.id', '=', 'activity_events.user_id')
            ->join('albums', 'albums.id', '=', 'activity_events.subject_id')
            ->where('activity_events.type', 'created')
            ->where('activity_events.subject_type', 'App\Models\Album')
            ->whereNotNull('albums.parent_id')
            ->whereColumn('albums.owner_id', '!=', 'activity_events.user_id')
            ->select('albums.id as album_id', 'activity_events.user_id')
            ->orderBy('albums.id')
            ->get()
            ->each(fn (object $row) => DB::table('albums')->where('id', $row->album_id)->update(['created_by' => $row->user_id]));
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
