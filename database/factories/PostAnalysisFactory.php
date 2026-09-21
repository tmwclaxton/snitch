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
            'hook' => 'Open on the brief, land on the proof.',
            'hook_window_end_sec' => 3,
            'visual_summary' => 'Talking-head cuts with a desk still and a mustard title card.',
            'idea' => 'Curiosity then proof: show the messy brief before the finished deck.',
            'format_notes' => 'Keep the desk still on screen longer than the talk.',
            'sfx' => [
                ['at_sec' => 0.5, 'label' => 'whoosh', 'role' => 'transition'],
            ],
            'music' => [
                'title' => 'Original audio',
                'source' => 'platform',
            ],
            'cta' => 'Save this for the next content meeting',
            'how_to_copy' => "1. Open on the messy brief.\n2. Cut to the still that proves it.\n3. End on the ask.",
            'transcript' => null,
            'concept' => 'Agency process as entertainment',
            'topics' => ['social_media', 'agency'],
            'custom_tags' => [],
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
            'how_to_copy' => null,
            'transcript' => null,
            'concept' => null,
            'topics' => null,
            'custom_tags' => null,
            'model' => null,
            'analyzed_at' => null,
        ]);
    }
}
