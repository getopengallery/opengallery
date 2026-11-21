<?php

use App\Models\Album;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "Unsorted uploads" and "Page images" become personal: `albums.system_album`
 * marks them (one of each per owner) instead of a fixed slug. The former
 * site-wide albums keep their owner; photos other people uploaded into them
 * move to those people's own album of the same kind.
 */
return new class extends Migration
{
    /** @var array<string, array{slug: string, title: string, description: string}> */
    private const KINDS = [
        'inbox' => [
            'slug' => 'unsorted-uploads',
            'title' => 'Unsorted uploads',
            'description' => 'Photos uploaded before they were added to an album. Add them to an album, then they leave this one.',
        ],
        'page-images' => [
            'slug' => 'page-images',
            'title' => 'Page images',
            'description' => 'Photos uploaded while writing pages. They are shown on those pages even though this album is private.',
        ],
    ];

    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('system_album', 20)->nullable()->after('owner_id');
            $table->unique(['owner_id', 'system_album']);
        });

        foreach (self::KINDS as $kind => $legacy) {
            DB::table('albums')->where('slug', $legacy['slug'])->update(['system_album' => $kind]);
        }

        $this->splitSystemAlbums();
    }

    /** Moves photos in each system album to their uploader's own album of that kind. */
    public function splitSystemAlbums(): void
    {
        foreach (DB::table('albums')->whereNotNull('system_album')->get() as $album) {
            $foreign = DB::table('album_photo')
                ->join('photos', 'photos.id', '=', 'album_photo.photo_id')
                ->join('users', 'users.id', '=', 'photos.uploader_id')
                ->where('album_photo.album_id', $album->id)
                ->when($album->owner_id, fn ($query) => $query->where('photos.uploader_id', '!=', $album->owner_id))
                ->orderBy('album_photo.sort_order')
                ->get(['album_photo.photo_id', 'photos.uploader_id']);

            foreach ($foreign->groupBy('uploader_id') as $uploaderId => $photos) {
                $target = $this->systemAlbumOf((int) $uploaderId, $album->system_album);
                $next = (int) DB::table('album_photo')->where('album_id', $target)->max('sort_order');

                foreach ($photos as $photo) {
                    $link = DB::table('album_photo')->where('album_id', $album->id)->where('photo_id', $photo->photo_id);

                    if (DB::table('album_photo')->where('album_id', $target)->where('photo_id', $photo->photo_id)->exists()) {
                        $link->delete();
                    } else {
                        $link->update(['album_id' => $target, 'sort_order' => ++$next]);
                    }
                }

                $this->fixCover($target);
            }

            $this->fixCover($album->id);
        }
    }

    private function systemAlbumOf(int $ownerId, string $kind): int
    {
        $existing = DB::table('albums')->where('owner_id', $ownerId)->where('system_album', $kind)->value('id');

        if ($existing) {
            return (int) $existing;
        }

        $legacy = self::KINDS[$kind];
        $id = DB::table('albums')->insertGetId([
            'owner_id' => $ownerId,
            'system_album' => $kind,
            'title' => $legacy['title'],
            'slug' => Str::slug($legacy['title']).'-'.Str::lower(Str::random(6)),
            'description' => $legacy['description'],
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('albums')->where('id', $id)->update(['tree_path' => Album::treeSegment(0, $legacy['title'], $id)]);

        return $id;
    }

    /** The cover must be one of the album's photos (or none). */
    private function fixCover(int $albumId): void
    {
        $photoIds = DB::table('album_photo')->where('album_id', $albumId)->orderBy('sort_order')->pluck('photo_id');
        $cover = DB::table('albums')->where('id', $albumId)->value('cover_photo_id');

        if ($cover === null || ! $photoIds->contains($cover)) {
            DB::table('albums')->where('id', $albumId)->update(['cover_photo_id' => $photoIds->first()]);
        }
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'system_album']);
            $table->dropColumn('system_album');
        });
    }
};
