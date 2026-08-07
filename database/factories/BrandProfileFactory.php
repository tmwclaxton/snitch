<?php

namespace Database\Factories;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandProfile>
 */
class BrandProfileFactory extends Factory
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
            'name' => fake()->company(),
            'website' => fake()->url(),
            'description' => fake()->sentence(),
            'own_handles' => [
                'instagram' => '@'.fake()->userName(),
                'tiktok' => '@'.fake()->userName(),
            ],
        ];
    }
}
