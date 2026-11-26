<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Photo;

/** A photo with its files in a new public album. */
function favoritesPublicPhoto(): Photo
{
    return galleryPhoto(Album::factory()->visibility(Visibility::Public)->create());
}
