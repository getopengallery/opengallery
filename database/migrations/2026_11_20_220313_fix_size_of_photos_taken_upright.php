<?php

use App\Models\Photo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Photos taken with the camera held upright (EXIF orientation 6 or 8) are
 * shown upright, but their width and height were stored as the camera wrote
 * them, so grids drew them with the wrong shape. They are swapped to how the
 * photos are seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('photos')
            ->where(fn ($query) => $query->where('exif', 'like', '%"Orientation":6%')->orWhere('exif', 'like', '%"Orientation":8%'))
            ->get(['id', 'width', 'height', 'exif'])
            ->filter(fn (object $photo) => in_array((int) (json_decode((string) $photo->exif, true)['Orientation'] ?? 1), Photo::TURNED_ORIENTATIONS, true))
            ->each(fn (object $photo) => DB::table('photos')->where('id', $photo->id)->update(['width' => $photo->height, 'height' => $photo->width]));
    }

    public function down(): void
    {
        // The swapped sizes are the right ones; nothing to undo.
    }
};
