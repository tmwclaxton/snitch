<?php

namespace Tests\Unit\Insights;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Insights\CompetitorInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitorInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_prefers_high_er_reels_and_extracts_phrases(): void
    {
        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'followers' => 1000,
        ]);

        Post::factory()->forAccount($account)->count(3)->create([
            'type' => PostType::Reel,
            'caption' => 'Shop the launch today? #drop @friend',
            'posted_at' => now()->setTime(9, 0),
            'metrics' => ['likes' => 100, 'comments' => 20],
        ]);

        $insights = app(CompetitorInsights::class);
        $computed = $insights->compute(collect([$account]), $account->posts()->get());

        $this->assertTrue($computed['hasAnyData']);
        $this->assertSame('Reel', $computed['formatStats'][0]['format']);
        $this->assertNotEmpty($computed['bestTimes']);

        $phrases = $insights->extractPhrases([
            'shop the launch today',
            'shop the launch today',
            'another caption about launch',
        ]);

        $this->assertContains('launch', array_column($phrases['words'], 'text'));
        $this->assertContains('shop launch', array_column($phrases['bigrams'], 'text'));
    }

    public function test_headline_needs_enough_format_samples(): void
    {
        $insights = app(CompetitorInsights::class);

        $this->assertNull($insights->headline([
            'bestTimes' => [['day' => 'Mon', 'hour' => 9, 'er' => 2, 'n' => 2]],
            'formatStats' => [['format' => 'Reel', 'er' => 3, 'n' => 2]],
            'bestLength' => null,
            'patternLifts' => [],
            'hasAnyData' => true,
        ]));

        $headline = $insights->headline([
            'bestTimes' => [['day' => 'Mon', 'hour' => 9, 'er' => 2, 'n' => 3]],
            'formatStats' => [['format' => 'Reel', 'er' => 3, 'n' => 3]],
            'bestLength' => null,
            'patternLifts' => [],
            'hasAnyData' => true,
        ]);

        $this->assertStringContainsString('Post a Reel around Mon 9am', (string) $headline);
    }
}
