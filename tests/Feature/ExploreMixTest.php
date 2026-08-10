<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Analysis\ExploreMixService;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExploreMixTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function explore_surfaces_strong_posts_before_weak_score_outliers(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.5,
            'snitch.explore.jitter' => 0.2,
            'snitch.explore.weight_exponent' => 2.0,
            'snitch.explore.seed_bucket_hours' => 6,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $strongA = $this->completedPost($account, now()->subDays(1));
        $strongB = $this->completedPost($account, now()->subDays(2));
        $weak = $this->completedPost($account, now()->subHour());

        WinnerInsight::factory()->forPost($strongA)->create(['score' => 92]);
        WinnerInsight::factory()->forPost($strongB)->create(['score' => 88]);
        WinnerInsight::factory()->forPost($weak)->create(['score' => 8]);

        $this->actingAs($user)
            ->get(route('explore.index', ['explore_seed' => 42]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 3)
                ->where('filters.explore_seed', 42)
                ->where('posts.data.0.id', fn ($id) => in_array((int) $id, [$strongA->id, $strongB->id], true))
                ->where('posts.data.1.id', fn ($id) => in_array((int) $id, [$strongA->id, $strongB->id], true))
                ->where('posts.data.2.id', $weak->id)
            );
    }

    #[Test]
    public function explore_mix_order_is_not_pure_newest_first(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.2,
            'snitch.explore.jitter' => 0.1,
            'snitch.explore.weight_exponent' => 3.0,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $newestWeak = $this->completedPost($account, now()->subMinute());
        $olderStrong = $this->completedPost($account, now()->subDays(5));

        WinnerInsight::factory()->forPost($newestWeak)->create(['score' => 12]);
        WinnerInsight::factory()->forPost($olderStrong)->create(['score' => 95]);

        $this->actingAs($user)
            ->get(route('explore.index', ['explore_seed' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 2)
                ->where('posts.data.0.id', $olderStrong->id)
                ->where('posts.data.1.id', $newestWeak->id)
            );
    }

    #[Test]
    public function explore_reuses_explore_seed_for_stable_order_across_pages(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.2,
            'snitch.explore.jitter' => 0.8,
            'snitch.explore.weight_exponent' => 1.2,
            'snitch.explore.max_candidates' => 500,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        for ($i = 0; $i < 30; $i++) {
            $post = $this->completedPost($account, now()->subHours($i + 1));
            WinnerInsight::factory()->forPost($post)->create(['score' => 70 + ($i % 10)]);
        }

        $seed = 918273;
        $pageOneIds = $this->orderForSeed($user, $seed);

        $this->assertCount(24, $pageOneIds);
        $this->assertSame($pageOneIds, $this->orderForSeed($user, $seed));

        $pageTwo = $this->actingAs($user)
            ->get(route('explore.index', ['explore_seed' => $seed, 'page' => 2]))
            ->assertOk();

        $pageTwo->assertInertia(fn (Assert $page) => $page
            ->component('explore/Index')
            ->where('filters.explore_seed', $seed)
            ->has('posts.data', 6)
        );

        $pageTwoIds = collect($pageTwo->inertiaProps('posts.data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertCount(6, $pageTwoIds);
        $this->assertEmpty(array_intersect($pageOneIds, $pageTwoIds));
    }

    #[Test]
    public function explore_different_seeds_change_order_among_strong_peers(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.2,
            'snitch.explore.jitter' => 0.95,
            'snitch.explore.weight_exponent' => 1.1,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        foreach ([88, 86, 84, 82, 80, 78, 76, 74] as $index => $score) {
            $post = $this->completedPost($account, now()->subDays($index + 1));
            WinnerInsight::factory()->forPost($post)->create(['score' => $score]);
        }

        $orderA = $this->orderForSeed($user, 11);
        $foundDifferent = false;

        foreach ([22, 33, 44, 55, 66, 77, 88, 99, 111, 222] as $seed) {
            $orderB = $this->orderForSeed($user, $seed);
            $this->assertEqualsCanonicalizing($orderA, $orderB);

            if ($orderA !== $orderB) {
                $foundDifferent = true;
                break;
            }
        }

        $this->assertTrue($foundDifferent, 'Expected at least one seed to rotate strong peer order.');
    }

    #[Test]
    public function explore_bare_visit_mints_a_new_seed_each_request(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = $this->completedPost($account, now()->subDay());
        WinnerInsight::factory()->forPost($post)->create(['score' => 77]);

        $first = $this->actingAs($user)->get(route('explore.index'))->assertOk();
        $second = $this->actingAs($user)->get(route('explore.index'))->assertOk();

        $seedA = (int) $first->inertiaProps('filters.explore_seed');
        $seedB = (int) $second->inertiaProps('filters.explore_seed');

        $this->assertGreaterThan(0, $seedA);
        $this->assertGreaterThan(0, $seedB);
        $this->assertNotSame($seedA, $seedB);

        $bucket = app(ExploreMixService::class)->seedFor((int) $user->id);
        $this->assertTrue(
            $seedA !== $bucket || $seedB !== $bucket,
            'Bare visits should not rely only on the 6h bucket seed.',
        );
    }

    #[Test]
    public function explore_with_filters_uses_bucket_seed_when_explore_seed_omitted(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config(['snitch.explore.seed_bucket_hours' => 6]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = $this->completedPost($account, now()->subDay());
        WinnerInsight::factory()->forPost($post)->create(['score' => 77]);

        $expected = app(ExploreMixService::class)->seedFor((int) $user->id);

        $first = $this->actingAs($user)
            ->get(route('explore.index', ['platform' => 'instagram']))
            ->assertOk();
        $second = $this->actingAs($user)
            ->get(route('explore.index', ['platform' => 'instagram']))
            ->assertOk();

        $this->assertSame($expected, (int) $first->inertiaProps('filters.explore_seed'));
        $this->assertSame($expected, (int) $second->inertiaProps('filters.explore_seed'));
    }

    /**
     * @return list<int>
     */
    private function orderForSeed(User $user, int $seed): array
    {
        $response = $this->actingAs($user)
            ->get(route('explore.index', ['explore_seed' => $seed]))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('explore/Index')
            ->where('filters.explore_seed', $seed)
        );

        return collect($response->inertiaProps('posts.data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function completedPost(TrackedAccount $account, mixed $postedAt): Post
    {
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => $postedAt,
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        return $post;
    }
}
