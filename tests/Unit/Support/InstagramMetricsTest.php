<?php

namespace Tests\Unit\Support;

use App\Support\InstagramMetrics;
use App\Support\SyncOptions;
use Tests\TestCase;

class InstagramMetricsTest extends TestCase
{
    public function test_hidden_zero_likes_with_views_become_null(): void
    {
        $metrics = InstagramMetrics::metricsFromPayload([
            'like_count' => 0,
            'comment_count' => 4,
            'play_count' => 800,
        ]);

        $this->assertNull($metrics['likes']);
        $this->assertTrue($metrics['like_count_hidden']);
        $this->assertSame(800, $metrics['views']);
        $this->assertSame(4, $metrics['comments']);
    }

    public function test_negative_likes_become_null(): void
    {
        $this->assertNull(InstagramMetrics::likesFromPayload([
            'like_count' => -1,
            'comment_count' => 2,
            'play_count' => 100,
        ]));
    }

    public function test_genuine_zero_likes_without_engagement_on_small_accounts_stay_zero(): void
    {
        $metrics = InstagramMetrics::metricsFromPayload([
            'like_count' => 0,
            'comment_count' => 0,
            'play_count' => 0,
            'follower_count' => 120,
        ]);

        $this->assertSame(0, $metrics['likes']);
        $this->assertArrayNotHasKey('like_count_hidden', $metrics);
    }

    public function test_all_zero_metrics_on_large_accounts_are_unavailable(): void
    {
        $metrics = InstagramMetrics::metricsFromPayload([
            'like_count' => 0,
            'comment_count' => 0,
            'play_count' => 0,
        ], accountFollowers: 1800);

        $this->assertNull($metrics['likes']);
        $this->assertTrue($metrics['like_count_hidden']);
        $this->assertSame(0, $metrics['views']);
        $this->assertSame(0, $metrics['comments']);
    }

    public function test_normalize_mapped_metrics_applies_follower_heuristic(): void
    {
        $metrics = InstagramMetrics::normalizeMappedMetrics([
            'views' => 0,
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'clicks' => 0,
        ], 900);

        $this->assertNull($metrics['likes']);
        $this->assertTrue($metrics['like_count_hidden']);
    }

    public function test_analysis_recency_covers_first_sync_without_widening_weekly_scrape(): void
    {
        config([
            'snitch.sync.recency_days' => 30,
            'snitch.sync.first_sync_recency_days' => 90,
            'snitch.sync.recency_days_max' => 90,
        ]);

        $this->assertSame(30, (new SyncOptions)->resolvedRecencyDays());
        $this->assertSame(90, SyncOptions::analysisRecencyDays());
    }
}
