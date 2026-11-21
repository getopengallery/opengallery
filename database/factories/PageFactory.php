<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->words(2, true),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'is_published' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(['is_published' => false]);
    }

    public function membersOnly(): static
    {
        return $this->state(['members_only' => true]);
    }
}
