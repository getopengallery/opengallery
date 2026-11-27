<?php

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use App\Support\MediaSizes;
use App\Support\PhotoCompression;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A real photo in $album whose stored sizes are replaced by known ones, so
 * the disk space figures can be checked exactly.
 *
 * @param  array<string, int>  $generated  conversion name => bytes of the generated file (none: not recorded yet)
 * @param  int|null  $beforeCompression  bytes before compression replaced the file (null: not recorded)
 * @param  bool  $compressed  whether compression ran on the file
 */
function photoWithStoredSizes(Album $album, int $bytes, array $generated = [], ?int $beforeCompression = null, bool $compressed = false, ?User $uploader = null): Photo
{
    $photo = galleryPhoto($album, $uploader);

    $properties = array_filter([
        MediaSizes::CONVERSIONS => $generated ?: null,
        MediaSizes::ORIGINAL => $beforeCompression,
        PhotoCompression::PROPERTY => $compressed ? ['max' => 800, 'quality' => 70] : null,
    ], fn (mixed $value) => $value !== null);

    Media::whereKey($photo->original()->getKey())->update(['size' => $bytes, 'custom_properties' => json_encode((object) $properties)]);

    return $photo;
}

/** Adds $count photo originals without files or recorded sizes (what MediaSizes::fillMissing() works through). */
function insertBareMedia(int $count): void
{
    $now = now()->toDateTimeString();
    $rows = array_map(fn (int $i) => [
        'model_type' => (new Photo)->getMorphClass(),
        'model_id' => $i,
        'collection_name' => Photo::COLLECTION,
        'name' => "bare-{$i}",
        'file_name' => "bare-{$i}.jpg",
        'disk' => 'gallery',
        'conversions_disk' => 'gallery',
        'size' => 100,
        'manipulations' => '[]',
        'custom_properties' => '{}',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ], range(1, $count));

    foreach (array_chunk($rows, 50) as $chunk) {
        DB::table('media')->insert($chunk);
    }
}

/** A recorded download of the original of $photo, by a guest unless $user is given. */
function photoDownloadEvent(Photo $photo, ?User $user = null, ?string $at = null): ActivityEvent
{
    return ActivityEvent::factory()->create([
        'type' => ActivityType::PhotoDownload,
        'user_id' => $user?->id,
        'visitor' => $user ? null : fake()->sha256(),
        'subject_type' => $photo->getMorphClass(),
        'subject_id' => $photo->id,
        'created_at' => $at ?? now(),
    ]);
}
