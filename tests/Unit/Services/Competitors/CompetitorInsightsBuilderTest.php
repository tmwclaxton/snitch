<?php

namespace Tests\Unit\Services\Competitors;

use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Competitors\CompetitorInsightsBuilder;
use App\Services\Dashboard\DashboardActivityBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitorInsightsBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_summarises_engagement_hashtags_and_keywords_for_one_account(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subDay(),
            'caption' => 'Bake club #sourdough #bakery with crusty loaves',
            'metrics' => [
                'views' => 1000,
                'likes' => 100,
                'comments' => 10,
                'shares' => 10,
            ],
        ]);
        Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subHours(6),
            'caption' => 'More #sourdough proofing overnight',
            'metrics' => [
                'views' => 1000,
                'likes' => 50,
                'comments' => 5,
                'shares' => 5,
            ],
        ]);

        $other = TrackedAccount::factory()->for($user)->create();
        Post::factory()->forAccount($other)->create([
            'caption' => '#ignoreme',
            'metrics' => [
                'views' => 99999,
                'likes' => 99999,
                'comments' => 1,
                'shares' => 1,
            ],
        ]);

        $insights = app(CompetitorInsightsBuilder::class)->forAccount($user, $account);

        $this->assertSame(2, $insights['engagement']['posts']);
        $this->assertSame(1000.0, $insights['engagement']['avg_views']);
        $this->assertSame(75.0, $insights['engagement']['avg_likes']);
        $this->assertSame(9.0, $insights['engagement']['avg_rate']);
        $this->assertSame('reel', $insights['format_mix'][0]['type']);
        $this->assertSame(2, $insights['format_mix'][0]['count']);
        $this->assertSame('sourdough', $insights['hashtags'][0]['term']);
        $this->assertSame(2, $insights['hashtags'][0]['count']);
        $this->assertNotEmpty($insights['keywords']);
        $this->assertContains('crusty', array_column($insights['keywords'], 'term'));
        $this->assertNotContains('bakery', array_column($insights['keywords'], 'term'));
        $this->assertCount(DashboardActivityBuilder::HEATMAP_WEEKS * 7, $insights['activity']['heatmap']);
    }
}
