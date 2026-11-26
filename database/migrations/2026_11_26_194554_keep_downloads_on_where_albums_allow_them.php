<?php

use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Downloads now have a site-wide switch that is off by default. Galleries
 * where an album already allows downloads keep them on, so updating does not
 * silently take downloads away.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', SiteSettings::DOWNLOADS_ENABLED)->exists()) {
            return;
        }

        if (DB::table('albums')->where('allow_downloads', true)->exists()) {
            Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        }
    }

    public function down(): void
    {
        // The switch stays; without it downloads would be off again.
    }
};
