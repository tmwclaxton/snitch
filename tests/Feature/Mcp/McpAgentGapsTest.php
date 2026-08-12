<?php

namespace Tests\Feature\Mcp;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
use App\Enums\PostType;
use App\Jobs\FindInfluencersJob;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\WorkflowGuide;
use App\Mcp\Tools\DismissInfluencerSuggestionsTool;
use App\Mcp\Tools\ExplorePostsTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListFeedTool;
use App\Mcp\Tools\ListWinnersTool;
use App\Mcp\Tools\WorkflowGuideTool;
use App\Models\AnalysisTerm;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class McpAgentGapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dismiss_influencer_suggestions_clears_run_and_pointers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $runId = (string) Str::uuid();
        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'suggestions' => [['platform' => 'instagram', 'handle' => 'creator']],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));
        Cache::put(FindInfluencersJob::activeCacheKeyFor($user->id), $runId, now()->addHours(2));

        SnitchServer::tool(DismissInfluencerSuggestionsTool::class, [
            'run_id' => $runId,
        ])
            ->assertOk()
            ->assertSee('"dismissed":true');

        $this->assertNull(Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId)));
        $this->assertNull(Cache::get(FindInfluencersJob::latestCacheKeyFor($user->id)));
        $this->assertNull(Cache::get(FindInfluencersJob::activeCacheKeyFor($user->id)));
    }

    public function test_list_winners_includes_snitch_url_and_filters_by_q(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create(['platform' => 'tiktok']);

        $seoPost = Post::factory()->forAccount($account)->create([
            'platform' => 'tiktok',
            'type' => PostType::Reel,
            'caption' => 'SEO AI tools that rank',
        ]);
        PostAnalysis::factory()->for($seoPost)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Stop guessing keywords',
            'how_to_copy' => 'Open with a failed search screenshot',
            'concept' => 'SEO tooling demo',
        ]);
        WinnerInsight::factory()->forPost($seoPost, $user)->create([
            'score' => 91,
            'why' => 'Strong SEO hook',
            'how_to_copy' => 'Remake the search fail open',
        ]);

        $footballPost = Post::factory()->forAccount($account)->create([
            'platform' => 'tiktok',
            'type' => PostType::Reel,
            'caption' => 'Match day goals',
        ]);
        PostAnalysis::factory()->for($footballPost)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Last minute winner',
            'concept' => 'Football highlights',
        ]);
        WinnerInsight::factory()->forPost($footballPost, $user)->create([
            'score' => 95,
            'why' => 'Viral football clip',
        ]);

        $this->actingAs($user);

        SnitchServer::tool(ListWinnersTool::class, [
            'q' => 'SEO',
            'limit' => 10,
        ])
            ->assertOk()
            ->assertSee('/feed/'.$seoPost->id)
            ->assertSee('snitch_url')
            ->assertSee('Remake the search fail open')
            ->assertSee('Stop guessing keywords')
            ->assertDontSee('Viral football clip');
    }

    public function test_list_winners_filters_by_topic_slug(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $term = AnalysisTerm::query()->create([
            'dimension' => AnalysisTermDimension::Topic,
            'slug' => 'ai_tools',
            'label' => 'AI tools',
        ]);

        $match = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        $matchAnalysis = PostAnalysis::factory()->for($match)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $matchAnalysis->terms()->attach($term->id);
        WinnerInsight::factory()->forPost($match, $user)->create(['score' => 80, 'why' => 'AI tools winner']);

        $other = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($other)->create(['status' => AnalysisStatus::Completed]);
        WinnerInsight::factory()->forPost($other, $user)->create(['score' => 99, 'why' => 'Unrelated winner']);

        $this->actingAs($user);

        SnitchServer::tool(ListWinnersTool::class, [
            'topics' => ['ai_tools'],
        ])
            ->assertOk()
            ->assertSee('AI tools winner')
            ->assertDontSee('Unrelated winner');
    }

    public function test_explore_posts_empty_q_does_not_dump_full_term_catalogue(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);
        BrandProfile::factory()->for($user)->create();

        for ($i = 0; $i < 5; $i++) {
            AnalysisTerm::query()->create([
                'dimension' => AnalysisTermDimension::Topic,
                'slug' => 'topic_'.$i,
                'label' => 'Topic '.$i,
            ]);
        }

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($post)->create(['status' => AnalysisStatus::Completed]);

        $this->actingAs($user);

        SnitchServer::tool(ExplorePostsTool::class, [
            'limit' => 5,
        ])
            ->assertOk()
            ->assertSee('snitch_url')
            ->assertSee('/feed/'.$post->id)
            ->assertDontSee('"terms":')
            ->assertDontSee('topic_0')
            ->assertDontSee('topic_4');
    }

    public function test_explore_posts_filters_by_topics_and_returns_hint_on_empty_search(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);
        BrandProfile::factory()->for($user)->create();

        $term = AnalysisTerm::query()->create([
            'dimension' => AnalysisTermDimension::Topic,
            'slug' => 'seo',
            'label' => 'SEO',
        ]);

        $account = TrackedAccount::factory()->for($user)->create();
        $match = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'caption' => 'Ranking pages',
        ]);
        $matchAnalysis = PostAnalysis::factory()->for($match)->create([
            'status' => AnalysisStatus::Completed,
            'concept' => 'SEO checklist',
        ]);
        $matchAnalysis->terms()->attach($term->id);

        $other = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'caption' => 'Cooking pasta',
        ]);
        PostAnalysis::factory()->for($other)->create(['status' => AnalysisStatus::Completed]);

        $this->actingAs($user);

        SnitchServer::tool(ExplorePostsTool::class, [
            'topics' => ['seo'],
            'limit' => 10,
        ])
            ->assertOk()
            ->assertSee('Ranking pages')
            ->assertDontSee('Cooking pasta');

        SnitchServer::tool(ExplorePostsTool::class, [
            'q' => 'zzzznohitszzzz',
            'limit' => 5,
        ])
            ->assertOk()
            ->assertSee('"hint"')
            ->assertSee('No posts matched');
    }

    public function test_get_post_and_list_feed_include_snitch_url(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($post)->create(['status' => AnalysisStatus::Completed]);

        $this->actingAs($user);

        SnitchServer::tool(GetPostTool::class, [
            'post_id' => $post->id,
        ])
            ->assertOk()
            ->assertSee('snitch_url')
            ->assertSee('/feed/'.$post->id);

        SnitchServer::tool(ListFeedTool::class, ['limit' => 5])
            ->assertOk()
            ->assertSee('snitch_url')
            ->assertSee('/feed/'.$post->id);
    }

    public function test_workflow_guide_content_plan_is_available(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $guide = WorkflowGuide::for('content_plan');
        $this->assertSame('content_plan', $guide['workflow']);
        $this->assertTrue(collect($guide['steps'])->contains(
            fn (array $step): bool => ($step['tool'] ?? null) === 'list_winners',
        ));
        $this->assertTrue(collect($guide['steps'])->contains(
            fn (array $step): bool => ($step['tool'] ?? null) === 'dismiss_influencer_suggestions',
        ));

        SnitchServer::tool(WorkflowGuideTool::class, [
            'workflow' => 'content_plan',
        ])
            ->assertOk()
            ->assertSee('"workflow":"content_plan"')
            ->assertSee('list_winners')
            ->assertSee('dismiss_influencer_suggestions');
    }
}
