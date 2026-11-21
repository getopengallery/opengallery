<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Album>
 */
class AlbumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'visibility' => Visibility::Private,
        ];
    }

    public function visibility(Visibility $visibility): static
    {
        return $this->state(['visibility' => $visibility]);
    }
}
