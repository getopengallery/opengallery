<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Licenses a photo can carry (App\Support\Licensing). The default pack is
 * inserted here, not by a seeder, so sites that update get it too. Admins
 * can edit, add and deactivate licenses; one used by a photo cannot be
 * deleted. `photos.license_id` is null for "no license given".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('title', 150)->nullable();
            $table->string('url', 255)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->foreignId('license_id')->nullable()->after('uploader_id')->index()->constrained()->restrictOnDelete();
        });

        $now = now();
        $pack = [
            ['All rights reserved', 'All rights reserved', null,
                'Others may not reuse, share or change the photo without the photographer\'s permission.'],
            ['CC BY 4.0', 'Creative Commons Attribution 4.0 International', 'https://creativecommons.org/licenses/by/4.0/',
                'Anyone may share and change the photo, also commercially, as long as they credit the photographer.'],
            ['CC BY-SA 4.0', 'Creative Commons Attribution-ShareAlike 4.0 International', 'https://creativecommons.org/licenses/by-sa/4.0/',
                'Anyone may share and change the photo, also commercially, if they credit the photographer and share changed versions under the same license.'],
            ['CC BY-ND 4.0', 'Creative Commons Attribution-NoDerivatives 4.0 International', 'https://creativecommons.org/licenses/by-nd/4.0/',
                'Anyone may share the photo unchanged, also commercially, if they credit the photographer. Changed versions may not be shared.'],
            ['CC BY-NC 4.0', 'Creative Commons Attribution-NonCommercial 4.0 International', 'https://creativecommons.org/licenses/by-nc/4.0/',
                'Anyone may share and change the photo if they credit the photographer, but not for commercial purposes.'],
            ['CC BY-NC-SA 4.0', 'Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International', 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
                'Anyone may share and change the photo if they credit the photographer, not for commercial purposes, and share changed versions under the same license.'],
            ['CC BY-NC-ND 4.0', 'Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International', 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
                'Anyone may share the photo unchanged if they credit the photographer, but not for commercial purposes. Changed versions may not be shared.'],
            ['CC0 1.0', 'CC0 1.0 Universal (Public Domain Dedication)', 'https://creativecommons.org/publicdomain/zero/1.0/',
                'The photographer gives up all rights: anyone may use the photo for any purpose, without credit.'],
        ];

        DB::table('licenses')->insert(array_map(fn (array $license, int $index): array => [
            'name' => $license[0],
            'title' => $license[1],
            'url' => $license[2],
            'description' => $license[3],
            'sort_order' => $index + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $pack, array_keys($pack)));
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('license_id');
        });

        Schema::dropIfExists('licenses');
    }
};
