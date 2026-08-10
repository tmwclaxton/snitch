<?php

namespace Tests\Feature\Mcp;

use App\Jobs\FindInfluencersJob;
use App\Jobs\SuggestCompetitorsJob;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\BrandContext;
use App\Mcp\Support\McpRuntime;
use App\Mcp\Tools\BillingStatusTool;
use App\Mcp\Tools\GetBrandTool;
use App\Mcp\Tools\InfluencerSearchStatusTool;
use App\Mcp\Tools\SuggestCompetitorsStatusTool;
use App\Mcp\Tools\WhoamiTool;
use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class McpRuntimeGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_instructions_cover_environment_and_queue(): void
    {
        $attributes = (new \ReflectionClass(SnitchServer::class))->getAttributes(Instructions::class);
        $this->assertNotEmpty($attributes);

        $instructions = $attributes[0]->newInstance()->value;

        $this->assertStringContainsString('whoami', $instructions);
        $this->assertStringContainsString('queue:work', $instructions);
        $this->assertStringContainsString('brand_warnings', $instructions);
    }

    public function test_whoami_includes_runtime_and_brand_warnings(): void
    {
        config(['app.url' => 'http://localhost:8000']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        SnitchServer::tool(WhoamiTool::class)
            ->assertOk()
            ->assertSee('runtime')
            ->assertSee('localhost')
            ->assertSee('brand_warnings')
            ->assertSee('No brand profile');
    }

    public function test_billing_status_includes_runtime_and_next_step(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        SnitchServer::tool(BillingStatusTool::class)
            ->assertOk()
            ->assertSee('runtime')
            ->assertSee('next_step')
            ->assertSee('can_run_billable');
    }

    public function test_get_brand_warns_when_website_missing(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'Opinly',
            'website' => null,
            'description' => 'AI opinions platform',
        ]);
        Sanctum::actingAs($user);

        SnitchServer::tool(GetBrandTool::class)
            ->assertOk()
            ->assertSee('brand_warnings')
            ->assertSee('website');

        $warnings = BrandContext::warningsFor($user->fresh());
        $this->assertNotEmpty($warnings);
        $this->assertTrue(collect($warnings)->contains(fn (string $w): bool => str_contains($w, 'website')));
    }

    public function test_suggest_status_includes_next_step_when_completed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $suggestId = (string) Str::uuid();
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'rivalbrand',
                    'display_name' => 'Rival Brand',
                ],
            ],
            'error' => null,
        ], now()->addHour());

        SnitchServer::tool(SuggestCompetitorsStatusTool::class, [
            'suggest_id' => $suggestId,
        ])
            ->assertOk()
            ->assertSee('next_step')
            ->assertSee('confirm_competitor_suggestions');
    }

    public function test_influencer_status_includes_next_step_when_completed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $runId = (string) Str::uuid();
        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'tiktok',
                    'handle' => 'creatorone',
                    'display_name' => 'Creator One',
                ],
            ],
            'error' => null,
        ], now()->addHour());

        SnitchServer::tool(InfluencerSearchStatusTool::class, [
            'run_id' => $runId,
        ])
            ->assertOk()
            ->assertSee('next_step')
            ->assertSee('keep_influencer');
    }

    public function test_mcp_runtime_warns_on_localhost(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);

        $snapshot = McpRuntime::snapshot();

        $this->assertSame('http://127.0.0.1:8000', $snapshot['app_url']);
        $this->assertNotEmpty($snapshot['warnings']);
        $this->assertTrue(collect($snapshot['warnings'])->contains(
            fn (string $w): bool => str_contains(strtolower($w), 'local')
        ));
    }
}
