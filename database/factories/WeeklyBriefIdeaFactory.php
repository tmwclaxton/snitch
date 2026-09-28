<?php

namespace Database\Factories;

use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyBriefIdea>
 */
class WeeklyBriefIdeaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekly_brief_id' => WeeklyBrief::factory(),
            'position' => 1,
            'format' => 'Reel',
            'hook' => fake()->sentence(6),
            'caption_angle' => fake()->sentence(12),
            'cta' => 'Comment your take',
            'hashtags' => ['#marketing', '#growth', '#content'],
            'recommended_day' => 'Tue',
            'recommended_hour' => 11,
            'inspired_by_post_ids' => [],
            'why' => fake()->sentence(10),
            'used_at' => null,
        ];
    }
}
