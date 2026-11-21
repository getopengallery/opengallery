<?php

namespace Database\Factories;

use App\Models\Album;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    /** Puts the photo into $album (photos can be in several albums). */
    public function inAlbum(Album $album): static
    {
        return $this->afterCreating(fn (Photo $photo) => $album->addPhotos($photo));
    }

    public function definition(): array
    {
        return [
            'title' => fake()->words(2, true),
        ];
    }
}
