<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WeeklyBrief;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyBrief>
 */
class WeeklyBriefFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_start' => now()->startOfWeek()->toDateString(),
            'status' => 'ready',
            'best_times' => [],
            'heat_grid' => [],
            'thin_data' => true,
            'credits_charged_pence' => 0,
            'was_free' => true,
            'generated_at' => now(),
        ];
    }
}
