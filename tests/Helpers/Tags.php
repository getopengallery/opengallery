<?php

use App\Models\Album;
use App\Models\Photo;
use App\Models\Tag;
use Illuminate\Testing\TestResponse;

/** Tags $record with $names, creating missing tags. */
function tagged(Album|Photo $record, string ...$names): Album|Photo
{
    $record->tags()->syncWithoutDetaching(array_map(fn (string $name) => Tag::firstOrCreate(['normalized' => Tag::normalize($name)], ['name' => $name])->id, $names));

    return $record;
}

/**
 * The ids of the albums listed on the response.
 *
 * @return list<int>
 */
function foundAlbums(TestResponse $response): array
{
    return collect($response->viewData('albums')->items())->pluck('id')->all();
}

/**
 * The ids of the photos listed on the response.
 *
 * @return list<int>
 */
function foundPhotos(TestResponse $response): array
{
    return collect($response->viewData('photos')?->items() ?? [])->pluck('id')->all();
}
