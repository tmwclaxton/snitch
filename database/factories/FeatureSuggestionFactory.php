<?php

namespace Database\Factories;

use App\Models\FeatureSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureSuggestion>
 */
class FeatureSuggestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'status' => 'open',
        ];
    }
}
