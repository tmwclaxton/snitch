<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\SnitchDailyPlatformStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SnitchDailyPlatformStat>
 */
class SnitchDailyPlatformStatFactory extends Factory
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
            'platform' => fake()->randomElement(Platform::cases()),
            'posts_count' => fake()->numberBetween(0, 40),
        ];
    }
}
