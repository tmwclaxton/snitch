<?php

namespace Tests\Feature\Mcp;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\GetAccountSummaryTool;
use App\Mcp\Tools\GetCompetitorPostsTool;
use App\Mcp\Tools\GetPostingTimesTool;
use App\Mcp\Tools\GetTopPostsTool;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpCurationFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_competitor_and_top_posts_exclude_own_unless_include_own(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $own = TrackedAccount::factory()->for($user)->own()->create([
            'platform' => Platform::Instagram,
            'handle' => 'brandown',
        ]);
        $rival = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        Post::factory()->forAccount($own)->create([
            'posted_at' => now()->subHours(2),
            'caption' => 'Own brand post visible',
            'metrics' => ['likes' => 100, 'comments' => 4, 'views' => 2000],
        ]);
        Post::factory()->forAccount($rival)->create([
            'posted_at' => now()->subHours(1),
            'caption' => 'Rival competitor post',
            'metrics' => ['likes' => 80, 'comments' => 3, 'views' => 1500],
        ]);

        $this->actingAs($user);

        SnitchServer::tool(GetCompetitorPostsTool::class, ['limit' => 20])
            ->assertOk()
            ->assertSee('Rival competitor post')
            ->assertDontSee('Own brand post visible');

        SnitchServer::tool(GetTopPostsTool::class, ['days' => 7, 'limit' => 10])
            ->assertOk()
            ->assertSee('Rival competitor post')
            ->assertDontSee('Own brand post visible');

        SnitchServer::tool(GetCompetitorPostsTool::class, [
            'limit' => 20,
            'include_own' => true,
        ])
            ->assertOk()
            ->assertSee('Own brand post visible')
            ->assertSee('Rival competitor post');
    }

    public function test_account_summary_returns_structured_json_when_no_own_account(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'onlyrival',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->actingAs($user);

        SnitchServer::tool(GetAccountSummaryTool::class)
            ->assertOk()
            ->assertSee('"has_own_account":false')
            ->assertSee('"tracker":null')
            ->assertSee('No own account marked');
    }

    public function test_account_summary_includes_since_first_snapshot_when_history_is_short(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $own = TrackedAccount::factory()->for($user)->own()->create([
            'platform' => Platform::Instagram,
            'handle' => 'shortgrowth',
            'followers' => 1200,
        ]);

        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 1000,
            'captured_on' => now()->subDays(5)->toDateString(),
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 1200,
            'captured_on' => now()->toDateString(),
        ]);

        $this->actingAs($user);

        SnitchServer::tool(GetAccountSummaryTool::class)
            ->assertOk()
            ->assertSee('"has_own_account":true')
            ->assertSee('since_first_snapshot')
            ->assertSee('"change":200')
            ->assertSee(now()->subDays(5)->toDateString());
    }

    public function test_posting_times_include_average_times_usual(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'timeslot',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
            'followers' => 5000,
        ]);

        // Build a thin history so later posts get a times-usual score.
        foreach (range(1, 5) as $i) {
            Post::factory()->forAccount($account)->create([
                'posted_at' => now()->subDays(20 - $i)->setTime(11, 0),
                'metrics' => ['likes' => 40 + $i, 'comments' => 2, 'views' => 800],
            ]);
        }
        Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay()->setTime(11, 0),
            'metrics' => ['likes' => 400, 'comments' => 20, 'views' => 8000],
        ]);

        $this->actingAs($user);

        SnitchServer::tool(GetPostingTimesTool::class, ['days' => 30])
            ->assertOk()
            ->assertSee('avg_times_usual')
            ->assertSee('"count":');
    }
}
