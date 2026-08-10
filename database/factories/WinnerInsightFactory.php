<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WinnerInsight>
 */
class WinnerInsightFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'post_id' => Post::factory(),
            'score' => fake()->randomFloat(2, 1, 100),
            'why' => fake()->sentence(),
            'how_to_copy' => fake()->paragraph(),
        ];
    }

    public function forPost(Post $post, ?User $user = null): static
    {
        return $this->state(function () use ($post, $user) {
            $userId = $user?->id
                ?? TrackedAccount::query()
                    ->where('social_account_id', $post->social_account_id)
                    ->value('user_id')
                ?? User::factory();

            return [
                'user_id' => $userId,
                'post_id' => $post->id,
            ];
        });
    }
}
