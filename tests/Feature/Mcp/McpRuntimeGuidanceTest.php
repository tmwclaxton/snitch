<?php

namespace Tests\Feature\Mcp;

use App\Jobs\FindInfluencersJob;
use App\Jobs\SuggestCompetitorsJob;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\BrandContext;
use App\Mcp\Support\McpJobWait;
use App\Mcp\Support\McpRuntime;
use App\Mcp\Tools\BillingStatusTool;
use App\Mcp\Tools\FindInfluencersTool;
use App\Mcp\Tools\GetBrandTool;
use App\Mcp\Tools\InfluencerSearchStatusTool;
use App\Mcp\Tools\SuggestCompetitorsStatusTool;
use App\Mcp\Tools\SuggestCompetitorsTool;
use App\Mcp\Tools\WhoamiTool;
use App\Models\BrandProfile;
use App\Models\User;
use App\Support\McpConnectionGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Attributes\Instructions;
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
        $this->actingAs($user);

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
        $this->actingAs($user);

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
        $this->actingAs($user);

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
        $this->actingAs($user);

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
        $this->actingAs($user);

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
        $this->assertTrue(collect($snapshot['warnings'])->contains(
            fn (string $w): bool => str_contains($w, 'single-threaded')
        ));
    }

    public function test_mcp_connection_guide_is_product_facing(): void
    {
        $payload = McpConnectionGuide::payload();
        $steps = collect($payload['general']['steps']);
        $allText = implode(' ', [
            ...$steps->all(),
            $payload['general']['snippet'],
            $payload['general']['blurb'],
        ]);

        $this->assertTrue($steps->contains(
            fn (string $step): bool => str_contains($step, 'whoami')
        ));
        $this->assertTrue($steps->contains(
            fn (string $step): bool => str_contains(strtolower($step), 'claim')
        ));
        $this->assertStringNotContainsString('queue:work', $allText);
        $this->assertStringNotContainsString('artisan serve', $allText);
        $this->assertStringNotContainsString('single-threaded', $allText);
        $this->assertStringNotContainsString('runtime.app_url', $allText);
    }

    public function test_brand_context_blocks_when_website_blank(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'Opinly',
            'website' => null,
            'description' => 'AI opinions',
        ]);

        $errors = BrandContext::blockingErrorsFor($user->fresh());
        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(fn (string $e): bool => str_contains($e, 'website')));

        $warnings = BrandContext::warningsFor($user->fresh());
        $this->assertTrue(collect($warnings)->contains(fn (string $w): bool => str_contains($w, 'website')));
    }

    public function test_brand_context_warns_empty_description_without_blocking(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'Opinly',
            'website' => 'https://opinly.ai',
            'description' => null,
        ]);

        $this->assertSame([], BrandContext::blockingErrorsFor($user->fresh()));
        $warnings = BrandContext::warningsFor($user->fresh());
        $this->assertTrue(collect($warnings)->contains(fn (string $w): bool => str_contains($w, 'description')));
    }

    public function test_brand_context_soft_warns_when_name_unrelated_to_host(): void
    {
        $this->assertTrue(BrandContext::nameLooksUnrelatedToWebsite('Opinly', 'https://grantgunner.org'));
        $this->assertFalse(BrandContext::nameLooksUnrelatedToWebsite('Grant Gunner', 'https://www.grantgunner.org'));

        $user = User::factory()->create();
        BrandProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'Opinly',
            'website' => 'https://grantgunner.org',
            'description' => 'Something',
        ]);

        $warnings = BrandContext::warningsFor($user->fresh());
        $this->assertTrue(collect($warnings)->contains(fn (string $w): bool => str_contains($w, 'unrelated')));
        $this->assertSame([], BrandContext::blockingErrorsFor($user->fresh()));
    }

    public function test_suggest_competitors_errors_when_brand_not_ready(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'Opinly',
            'website' => null,
            'description' => 'AI opinions',
        ]);
        $this->actingAs($user);

        SnitchServer::tool(SuggestCompetitorsTool::class, [
            'wait_seconds' => 0,
        ])->assertHasErrors([
            'Brand website is blank',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_find_influencers_errors_when_brand_not_ready(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(FindInfluencersTool::class, [
            'platform' => 'tiktok',
            'brief' => 'Creators',
            'wait_seconds' => 0,
        ])->assertHasErrors([
            'No brand profile',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_mcp_job_wait_returns_immediately_when_already_terminal(): void
    {
        $key = 'mcp-job-wait-test:'.Str::uuid();
        Cache::put($key, ['status' => 'completed', 'suggestions' => [['handle' => 'x']]], now()->addMinute());

        $result = McpJobWait::untilTerminal($key, 5);

        $this->assertFalse($result['timed_out']);
        $this->assertSame('completed', $result['payload']['status'] ?? null);
        $this->assertLessThan(2, $result['waited_seconds']);
    }

    public function test_mcp_job_wait_zero_seconds_does_not_block(): void
    {
        $key = 'mcp-job-wait-zero:'.Str::uuid();
        Cache::put($key, ['status' => 'queued'], now()->addMinute());

        $result = McpJobWait::untilTerminal($key, 0);

        $this->assertTrue($result['timed_out']);
        $this->assertSame(0, $result['waited_seconds']);
        $this->assertSame('queued', $result['payload']['status'] ?? null);
    }

    public function test_suggest_competitors_defaults_to_zero_wait_seconds(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        SnitchServer::tool(SuggestCompetitorsTool::class, [])
            ->assertOk()
            ->assertSee('"waited_seconds":0')
            ->assertSee('"queued":true');

        Queue::assertPushed(SuggestCompetitorsJob::class);
    }

    public function test_mcp_job_wait_extends_execution_time_for_long_waits(): void
    {
        $previous = (int) ini_get('max_execution_time');
        ini_set('max_execution_time', '30');

        try {
            McpJobWait::extendExecutionTime(45);
            $limit = (int) ini_get('max_execution_time');
            $this->assertGreaterThanOrEqual(45, $limit);
        } finally {
            ini_set('max_execution_time', (string) $previous);
        }
    }

    public function test_suggest_status_wait_seconds_returns_terminal_payload(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $suggestId = (string) Str::uuid();
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'rivalbrand',
                ],
            ],
            'error' => null,
        ], now()->addHour());

        SnitchServer::tool(SuggestCompetitorsStatusTool::class, [
            'suggest_id' => $suggestId,
            'wait_seconds' => 5,
        ])
            ->assertOk()
            ->assertSee('confirm_competitor_suggestions')
            ->assertSee('waited_seconds');
    }
}
