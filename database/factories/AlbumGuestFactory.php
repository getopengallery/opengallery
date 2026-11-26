<?php

namespace Database\Factories;

use App\Models\Album;
use App\Models\AlbumGuest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Guests get a random stored token hash; tests that need the link create
 * guests through App\Support\AlbumGuests::invite(), which returns it.
 *
 * @extends Factory<AlbumGuest>
 */
class AlbumGuestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'album_id' => Album::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token' => hash('sha256', Str::random(40)),
        ];
    }
}
