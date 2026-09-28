<?php

namespace Database\Factories;

use App\Models\MonthlyReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReport>
 */
class MonthlyReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $month = now()->startOfMonth()->subMonth();

        return [
            'user_id' => User::factory(),
            'month_start' => $month->toDateString(),
            'payload' => [
                'month' => $month->format('Y-m'),
                'month_label' => $month->format('F Y'),
                'kpis' => [
                    'followers' => [
                        'you' => 1200,
                        'you_prev' => 1000,
                        'you_change' => 20.0,
                        'peer' => 900,
                        'peer_prev' => 850,
                        'peer_change' => 5.9,
                    ],
                    'posts' => [
                        'you' => 8,
                        'you_prev' => 6,
                        'you_change' => 33.3,
                        'peer' => 7,
                        'peer_prev' => 7,
                        'peer_change' => 0.0,
                    ],
                    'engagement_rate' => [
                        'you' => 2.5,
                        'you_prev' => 2.1,
                        'you_change' => 19.0,
                        'peer' => 2.0,
                        'peer_prev' => 2.0,
                        'peer_change' => 0.0,
                    ],
                    'avg_multiplier' => [
                        'you' => 1.4,
                        'you_prev' => 1.2,
                        'you_change' => 16.7,
                        'peer' => 1.1,
                        'peer_prev' => 1.0,
                        'peer_change' => 10.0,
                    ],
                ],
                'own_top_posts' => [],
                'competitor_winners' => [],
                'what_changed' => ['Followers up 20% vs last month.'],
                'next_focus' => [],
            ],
        ];
    }
}
