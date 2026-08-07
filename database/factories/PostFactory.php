<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
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
            'tracked_account_id' => TrackedAccount::factory(),
            'platform' => Platform::Instagram,
            'type' => PostType::Reel,
            'external_id' => (string) fake()->unique()->numerify('##########'),
            'url' => fake()->url(),
            'posted_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'caption' => fake()->sentence(),
            'media_url' => fake()->url(),
            'metrics' => [
                'views' => fake()->numberBetween(100, 100000),
                'likes' => fake()->numberBetween(10, 10000),
                'comments' => fake()->numberBetween(0, 500),
                'shares' => fake()->numberBetween(0, 200),
            ],
            'raw_payload' => ['source' => 'factory'],
        ];
    }

    public function forAccount(TrackedAccount $account): static
    {
        return $this->state(fn () => [
            'user_id' => $account->user_id,
            'tracked_account_id' => $account->id,
            'platform' => $account->platform,
        ]);
    }
}
