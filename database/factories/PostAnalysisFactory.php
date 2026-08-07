<?php

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Models\Post;
use App\Models\PostAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostAnalysis>
 */
class PostAnalysisFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'status' => AnalysisStatus::Completed,
            'hook' => fake()->sentence(),
            'hook_window_end_sec' => 3,
            'visual_summary' => fake()->paragraph(),
            'idea' => fake()->sentence(),
            'format_notes' => fake()->sentence(),
            'sfx' => [
                ['at_sec' => 0.5, 'label' => 'whoosh', 'role' => 'transition'],
            ],
            'music' => [
                'title' => fake()->words(3, true),
                'artist' => fake()->name(),
            ],
            'cta' => fake()->sentence(),
            'model' => 'qwen3.7-flash',
            'error_message' => null,
            'analyzed_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AnalysisStatus::Pending,
            'hook' => null,
            'hook_window_end_sec' => null,
            'visual_summary' => null,
            'idea' => null,
            'format_notes' => null,
            'sfx' => null,
            'music' => null,
            'cta' => null,
            'model' => null,
            'analyzed_at' => null,
        ]);
    }
}
