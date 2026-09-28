<?php

namespace Tests\Unit\Services\Dashboard;

use App\Enums\PostType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMathTest extends TestCase
{
    use RefreshDatabase;

    private DashboardMath $math;

    protected function setUp(): void
    {
        parent::setUp();
        $this->math = new DashboardMath;
    }

    public function test_engagement_rate_never_exceeds_100_percent_for_sane_data(): void
    {
        $post = new Post([
            'metrics' => ['likes' => 50, 'comments' => 10],
        ]);

        $er = $this->math->engagementRate($post, 1000);

        $this->assertNotNull($er);
        $this->assertSame(6.0, $er);
        $this->assertLessThanOrEqual(100.0, $er);
    }

    public function test_engagement_rate_is_null_when_followers_unknown(): void
    {
        $post = new Post([
            'metrics' => ['likes' => 10, 'comments' => 1],
        ]);

        $this->assertNull($this->math->engagementRate($post, null));
        $this->assertNull($this->math->engagementRate($post, 0));
    }

    public function test_hidden_likes_are_excluded(): void
    {
        $hidden = new Post(['metrics' => ['likes' => -1, 'comments' => 2]]);
        $nullLikes = new Post(['metrics' => ['likes' => null, 'comments' => 2]]);
        $flagged = new Post(['metrics' => ['likes' => null, 'comments' => 2, 'like_count_hidden' => true, 'views' => 500]]);

        $this->assertTrue($this->math->isHiddenLikes($hidden));
        $this->assertTrue($this->math->isHiddenLikes($nullLikes));
        $this->assertTrue($this->math->isHiddenLikes($flagged));
        $this->assertNull($this->math->interactions($hidden));
        $this->assertNull($this->math->interactions($flagged));
    }

    public function test_performance_index_uses_only_prior_posts(): void
    {
        $social = SocialAccount::factory()->create();

        $priors = [];

        for ($i = 0; $i < 12; $i++) {
            $priors[] = Post::factory()->forSocialAccount($social)->create([
                'posted_at' => now()->subDays(40 - $i),
                'metrics' => ['likes' => 10, 'comments' => 0],
            ]);
        }

        $winner = Post::factory()->forSocialAccount($social)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['likes' => 40, 'comments' => 0],
        ]);

        $future = Post::factory()->forSocialAccount($social)->create([
            'posted_at' => now()->addDay(),
            'metrics' => ['likes' => 1000, 'comments' => 0],
        ]);

        $accountPosts = collect([...$priors, $winner, $future])->sortByDesc('posted_at')->values();
        $result = $this->math->performanceIndex($winner, $accountPosts);

        $this->assertNotNull($result['pi']);
        $this->assertSame(4.0, $result['pi']);
        $this->assertSame(12, $result['prior_n']);
        $this->assertFalse($result['early']);
    }

    public function test_heatmap_buckets_use_europe_london_across_dst(): void
    {
        // 2025-03-30 01:30 UTC = 02:30 BST after clocks spring forward.
        $utc = CarbonImmutable::parse('2025-03-30 01:30:00', 'UTC');
        $bucket = $this->math->londonBucket($utc);

        $this->assertNotNull($bucket);
        $this->assertSame(6, $bucket['dow']); // Sunday
        $this->assertSame(2, $bucket['hour']);
        $this->assertSame(0, $bucket['block']); // 00-04
    }

    public function test_format_label_treats_clips_product_type_as_reel(): void
    {
        $post = new Post([
            'type' => PostType::Video,
            'url' => 'https://www.instagram.com/p/abc',
            'raw_payload' => ['productType' => 'clips'],
        ]);

        $this->assertSame('Reel', $this->math->formatLabel($post));
    }

    public function test_insufficient_reason_never_shows_zero_percent(): void
    {
        $this->assertSame('Not enough posts yet (n=3)', $this->math->insufficientReason(3));
    }

    public function test_median_of_even_count(): void
    {
        $this->assertSame(2.5, $this->math->median([1, 2, 3, 4]));
    }

    public function test_hook_returns_full_first_line_without_ellipsis(): void
    {
        $long = str_repeat('Winning hook words ', 12).'end';

        $this->assertSame($long, $this->math->hook($long."\nsecond line ignored"));
        $this->assertStringNotContainsString('…', $this->math->hook($long));
        $this->assertSame('', $this->math->hook('   '));
    }
}
