<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WinnerRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WinnerRule>
 */
class WinnerRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'preset' => 'balanced',
            'min_engagement_rate' => 3,
            'min_views' => 1000,
            'min_likes' => 100,
            'recency_days' => 30,
            'weights' => [
                'views' => 0.4,
                'likes' => 0.3,
                'comments' => 0.2,
                'shares' => 0.1,
            ],
            'advanced' => [],
        ];
    }
}
