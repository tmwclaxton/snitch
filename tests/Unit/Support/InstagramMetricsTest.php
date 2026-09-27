<?php

namespace Tests\Unit\Support;

use App\Support\InstagramMetrics;
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

    public function test_genuine_zero_likes_without_engagement_stay_zero(): void
    {
        $metrics = InstagramMetrics::metricsFromPayload([
            'like_count' => 0,
            'comment_count' => 0,
            'play_count' => 0,
        ]);

        $this->assertSame(0, $metrics['likes']);
        $this->assertArrayNotHasKey('like_count_hidden', $metrics);
    }
}
