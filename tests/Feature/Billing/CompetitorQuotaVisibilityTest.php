<?php

namespace Tests\Feature\Billing;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompetitorQuotaVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function oldest_accounts_keep_quota_slots(): void
    {
        $user = User::factory()->freePlan()->create();
        $accounts = TrackedAccount::factory()->count(5)->for($user)->create();
        $ids = app(PlanEntitlementService::class)->inQuotaTrackedAccountIds($user);

        $this->assertSame(
            $accounts->sortBy('id')->take(3)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $ids,
        );
        $this->assertSame(2, app(PlanEntitlementService::class)->summary($user)['over_quota_competitors']);
    }

    #[Test]
    public function competitors_index_marks_over_quota_accounts(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(4)->for($user)->create();

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->has('accounts', 4)
                ->where('competitorCap.over_quota_competitors', 1)
                ->where('accounts', function ($accounts): bool {
                    $rows = collect($accounts);
                    $inQuota = $rows->where('in_quota', true)->count();
                    $over = $rows->where('in_quota', false)->count();

                    return $inQuota === 3 && $over === 1;
                })
            );
    }

    #[Test]
    public function feed_hides_posts_from_over_quota_accounts(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();

        $inQuota = TrackedAccount::factory()->for($user)->create();
        TrackedAccount::factory()->count(2)->for($user)->create();
        $overQuota = TrackedAccount::factory()->for($user)->create();

        $visible = Post::factory()->forAccount($inQuota)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subHour(),
        ]);
        Post::factory()->forAccount($overQuota)->create([
            'type' => PostType::Reel,
            'posted_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $visible->id)
            );
    }

    #[Test]
    public function competitor_show_hides_reels_when_over_quota(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();

        TrackedAccount::factory()->count(3)->for($user)->create();
        $overQuota = TrackedAccount::factory()->for($user)->create();
        Post::factory()->forAccount($overQuota)->create([
            'type' => PostType::Reel,
        ]);

        $this->actingAs($user)
            ->get(route('competitors.show', $overQuota))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Show')
                ->where('inQuota', false)
                ->where('account.in_quota', false)
                ->has('posts', 0)
                ->has('winners', 0)
            );
    }

    #[Test]
    public function over_quota_post_detail_is_forbidden(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(3)->for($user)->create();
        $overQuota = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($overQuota)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->actingAs($user)
            ->get(route('feed.show', $post))
            ->assertForbidden();
    }

    #[Test]
    public function winners_exclude_over_quota_accounts(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();

        $inQuota = TrackedAccount::factory()->for($user)->create();
        TrackedAccount::factory()->count(2)->for($user)->create();
        $overQuota = TrackedAccount::factory()->for($user)->create();

        $visiblePost = Post::factory()->forAccount($inQuota)->create(['type' => PostType::Reel]);
        $hiddenPost = Post::factory()->forAccount($overQuota)->create(['type' => PostType::Reel]);

        WinnerInsight::factory()->for($user)->for($visiblePost, 'post')->create(['score' => 90]);
        WinnerInsight::factory()->for($user)->for($hiddenPost, 'post')->create(['score' => 99]);

        $this->actingAs($user)
            ->get(route('winners.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('winners/Index')
                ->has('winners', 1)
                ->where('winners.0.post.id', $visiblePost->id)
            );
    }
}
