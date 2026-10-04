<?php

namespace Database\Factories;

use App\Models\DailyBrief;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyBrief>
 */
class DailyBriefFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'brief_date' => now('Europe/London')->toDateString(),
            'status' => 'ready',
            'headline' => 'Post one short Reel tonight and reply to yesterday\'s comments.',
            'payload' => [
                'headline' => 'Post one short Reel tonight and reply to yesterday\'s comments.',
                'big_numbers' => [
                    ['label' => 'Followers', 'value' => '98', 'note' => 'Weekly change not yet daily'],
                    ['label' => 'Change since yesterday', 'value' => 'New', 'note' => 'Daily tracking started '.now('Europe/London')->format('j M').'; first comparison tomorrow'],
                    ['label' => 'Your posts this week', 'value' => '3', 'note' => null],
                    ['label' => 'Competitor posts in the last day', 'value' => '2', 'note' => null],
                ],
                'actions' => [],
                'own_last_7_days' => [],
                'competitor_moves' => [],
                'trends' => [],
                'ads' => ['items' => [], 'empty_label' => 'No ads found'],
                'watch' => [],
                'data_freshness' => [],
            ],
            'facts' => [],
            'model' => null,
            'llm_attempts' => 0,
            'credits_charged_pence' => 0,
            'was_free' => true,
            'generated_at' => now(),
        ];
    }
}
