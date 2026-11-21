<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityEvent>
 */
class ActivityEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ActivityType::Login,
            'user_id' => User::factory(),
            'created_at' => now(),
        ];
    }

    public function guestView(): static
    {
        return $this->state(fn () => [
            'type' => ActivityType::AlbumView,
            'user_id' => null,
            'visitor' => fake()->sha256(),
        ]);
    }
}
