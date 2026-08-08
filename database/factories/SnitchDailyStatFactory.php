<?php

namespace Database\Factories;

use App\Models\SnitchDailyStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SnitchDailyStat>
 */
class SnitchDailyStatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->date(),
            'posts_count' => fake()->numberBetween(0, 80),
            'analyses_count' => fake()->numberBetween(0, 60),
            'winners_count' => fake()->numberBetween(0, 20),
        ];
    }
}
