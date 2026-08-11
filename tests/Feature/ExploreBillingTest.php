<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\BillingVendor;
use App\Enums\PostType;
use App\Enums\TrackedAccountKind;
use App\Models\BrandProfile;
use App\Models\CreditLedgerEntry;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExploreBillingTest extends TestCase
{
    use RefreshDatabase;

    private UsageBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.min_run_balance_pence' => 20,
            'billing.price_multiplier' => 1.3,
            'billing.usd_to_gbp' => 1.0,
        ]);

        $this->billing = app(UsageBillingService::class);
    }

    public function test_explore_search_charges_half_penny_idempotently(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->billing->creditFromTopUp($user, 1000, 'topup:explore-search');
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'steam asmr cooking',
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['q' => 'steam asmr']))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('explore.index', ['q' => 'steam asmr', 'page' => 1]))
            ->assertOk();

        $entries = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('action', 'explore.search')
            ->get();

        $this->assertCount(1, $entries);
        $this->assertSame(BillingVendor::Snitch, $entries->first()->vendor);
        $this->assertSame(-0.5, (float) $entries->first()->amount_pence);
        // Factory claim bonus (£5) + top-up (£10) minus 0.5p search.
        $this->assertSame(1499.5, $this->billing->balancePence($user));
    }

    public function test_viewing_non_tracked_competitor_reel_charges_tenth_penny_once(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        BrandProfile::factory()->for($viewer)->create();
        $this->billing->creditFromTopUp($viewer, 1000, 'topup:explore-view');

        $foreign = TrackedAccount::factory()->for($owner)->create([
            'kind' => TrackedAccountKind::Competitor,
            'handle' => 'foreign_rival',
        ]);
        $post = Post::factory()->forAccount($foreign)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->actingAs($viewer)
            ->get(route('feed.show', $post))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('feed.show', $post))
            ->assertOk();

        $entries = CreditLedgerEntry::query()
            ->where('user_id', $viewer->id)
            ->where('action', 'explore.view')
            ->get();

        $this->assertCount(1, $entries);
        $this->assertSame(-0.1, (float) $entries->first()->amount_pence);
        $this->assertSame(
            sprintf('explore.view:%d:%d', $viewer->id, $post->id),
            $entries->first()->idempotency_key,
        );
    }

    public function test_viewing_tracked_competitor_reel_is_free(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->billing->creditFromTopUp($user, 1000, 'topup:explore-view-free');

        $account = TrackedAccount::factory()->for($user)->create([
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $post = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->actingAs($user)
            ->get(route('feed.show', $post))
            ->assertOk();

        $this->assertSame(0, CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('action', 'explore.view')
            ->count());
        // Factory claim bonus (£5) + top-up (£10); tracked snitch views are free.
        $this->assertSame(1500.0, $this->billing->balancePence($user));
    }

    public function test_insufficient_credits_omits_explore_search_results(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        while ($this->billing->canAccessProduct($user)) {
            $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);
        }

        $searchChargesBefore = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('action', 'explore.search')
            ->count();

        // Hard paywall: safe GETs render the shell with empty product props
        // instead of redirecting (security is server-side omission).
        $this->actingAs($user)
            ->get(route('explore.index', ['q' => 'anything']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->where('subscription.paywall.blocked', true)
                ->where('posts.data', [])
                ->where('posts.total', 0)
                ->where('terms.hook_type', [])
            );

        $this->assertSame(
            $searchChargesBefore,
            CreditLedgerEntry::query()
                ->where('user_id', $user->id)
                ->where('action', 'explore.search')
                ->count(),
        );
    }
}
