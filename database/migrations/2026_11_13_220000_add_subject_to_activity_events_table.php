<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_events', function (Blueprint $table) {
            // What was changed (album, user, group, role...) and how.
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activity_events', function (Blueprint $table) {
            $table->dropMorphs('subject');
            $table->dropColumn('properties');
        });
    }
};
