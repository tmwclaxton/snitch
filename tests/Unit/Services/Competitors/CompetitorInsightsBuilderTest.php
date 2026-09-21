<?php

namespace Tests\Unit\Services\Competitors;

use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Competitors\CompetitorInsightsBuilder;
use App\Services\Dashboard\DashboardActivityBuilder;
use Carbon\CarbonImmutable;
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
            'type' => PostType::Image,
            'posted_at' => now()->subHours(6),
            'caption' => 'These should start today and say more #sourdough proofing overnight',
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
        $this->assertEqualsCanonicalizing(
            ['image', 'reel'],
            array_column($insights['format_mix'], 'type'),
        );
        $this->assertSame('sourdough', $insights['hashtags'][0]['term']);
        $this->assertSame(2, $insights['hashtags'][0]['count']);
        $this->assertNotEmpty($insights['keywords']);
        $this->assertContains('crusty', array_column($insights['keywords'], 'term'));
        $this->assertContains('proofing', array_column($insights['keywords'], 'term'));
        $this->assertNotContains('bakery', array_column($insights['keywords'], 'term'));
        $this->assertNotContains('should', array_column($insights['keywords'], 'term'));
        $this->assertNotContains('these', array_column($insights['keywords'], 'term'));
        $this->assertNotContains('today', array_column($insights['keywords'], 'term'));
        $this->assertNotContains('start', array_column($insights['keywords'], 'term'));
        $this->assertNotContains('say', array_column($insights['keywords'], 'term'));
        $this->assertCount(DashboardActivityBuilder::HEATMAP_WEEKS * 7, $insights['activity']['heatmap']);
    }

    public function test_it_summarises_the_full_user_corpus_for_the_dashboard(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 12:00:00'));

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $first = TrackedAccount::factory()->for($user)->create();
        $second = TrackedAccount::factory()->for($user)->create();

        Post::factory()->forAccount($first)->create([
            'type' => PostType::Reel,
            'posted_at' => CarbonImmutable::parse('2026-08-04 18:00:00'),
            'caption' => 'Studio drop #later #planning',
            'metrics' => [
                'views' => 200,
                'likes' => 20,
                'comments' => 2,
                'shares' => 2,
            ],
        ]);
        Post::factory()->forAccount($second)->create([
            'type' => PostType::Carousel,
            'posted_at' => CarbonImmutable::parse('2026-08-04 18:00:00'),
            'caption' => 'Culture notes #later',
            'metrics' => [
                'views' => 100,
                'likes' => 10,
                'comments' => 1,
                'shares' => 1,
            ],
        ]);

        $insights = app(CompetitorInsightsBuilder::class)->forUser($user);

        $this->assertSame(2, $insights['engagement']['posts']);
        $this->assertEqualsCanonicalizing(
            ['carousel', 'reel'],
            array_column($insights['format_mix'], 'type'),
        );
        $this->assertSame('later', $insights['hashtags'][0]['term']);
        $this->assertSame(2, $insights['hashtags'][0]['count']);
        $this->assertSame('6pm', $insights['playbook']['peak_hour_label']);
        $this->assertContains($insights['playbook']['top_format'], ['reel', 'carousel']);
        $this->assertSame('later', $insights['playbook']['top_hashtag']);

        CarbonImmutable::setTestNow();
    }
}
