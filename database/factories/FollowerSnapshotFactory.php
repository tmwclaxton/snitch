<?php

namespace Database\Factories;

use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowerSnapshot>
 */
class FollowerSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'followers' => fake()->numberBetween(100, 500000),
            'captured_on' => now()->toDateString(),
        ];
    }
}
