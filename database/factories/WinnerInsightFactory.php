<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use App\Models\WinnerInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WinnerInsight>
 */
class WinnerInsightFactory extends Factory
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
            'post_id' => Post::factory(),
            'score' => fake()->randomFloat(2, 1, 100),
            'why' => fake()->sentence(),
            'how_to_copy' => fake()->paragraph(),
        ];
    }

    public function forPost(Post $post): static
    {
        return $this->state(fn () => [
            'user_id' => $post->user_id,
            'post_id' => $post->id,
        ]);
    }
}
