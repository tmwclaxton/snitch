<?php

namespace App\Mcp\Tools;

use App\Jobs\FindInfluencersJob;
use App\Mcp\Support\BrandContext;
use App\Mcp\Support\McpAuth;
use App\Mcp\Support\McpJobWait;
use App\Mcp\Support\McpRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('find_influencers')]
#[Description('Queue influencer discovery for one platform (billable). Requires brand name + website. Optional wait_seconds (default ~22, max 45) polls cache so a completed run often returns suggestions in one call. Does NOT track anyone - after status completed call keep_influencer or discard_influencer.')]
class FindInfluencersTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $blocked = BrandContext::assertReady($user);
        if ($blocked !== null) {
            return $blocked;
        }

        $data = $request->validate([
            'platform' => ['required', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'brief' => ['required', 'string', 'max:5000'],
            'language' => ['nullable', 'string', 'max:32'],
            'min_followers' => ['nullable', 'integer', 'min:0'],
            'max_followers' => ['nullable', 'integer', 'min:0'],
            'wait_seconds' => ['nullable', 'integer', 'min:0', 'max:45'],
        ]);

        $runId = (string) Str::uuid();
        $filters = [
            'platforms' => [$data['platform']],
            'language' => $data['language'] ?? null,
            'min_followers' => $data['min_followers'] ?? null,
            'max_followers' => $data['max_followers'] ?? null,
            'brief' => $data['brief'],
        ];

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'queued',
            'filters' => $filters,
            'brief' => $data['brief'],
            'suggestions' => [],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(24));

        FindInfluencersJob::dispatch($user->id, $runId, $filters);

        $brandWarnings = BrandContext::warningsFor($user);
        $runtime = McpRuntime::snapshot();

        $wait = McpJobWait::untilTerminal(
            FindInfluencersJob::cacheKeyFor($user->id, $runId),
            isset($data['wait_seconds']) ? (int) $data['wait_seconds'] : null,
        );

        return $this->responseForWait($runId, $wait, $brandWarnings, $runtime);
    }

    /**
     * @param  array{payload: mixed, timed_out: bool, waited_seconds: int}  $wait
     * @param  list<string>  $brandWarnings
     * @param  array<string, mixed>  $runtime
     */
    private function responseForWait(string $runId, array $wait, array $brandWarnings, array $runtime): Response
    {
        $payload = $wait['payload'];
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $terminal = McpJobWait::isTerminal($payload);

        $base = [
            'run_id' => $runId,
            'waited_seconds' => $wait['waited_seconds'],
            'brand_warnings' => $brandWarnings,
            'runtime' => [
                'app_url' => $runtime['app_url'],
                'pending_jobs' => $runtime['pending_jobs'],
                'warnings' => $runtime['warnings'],
            ],
            'payload' => $payload,
        ];

        if ($status === 'failed') {
            return Response::json([
                ...$base,
                'queued' => false,
                'note' => 'Influencer search failed. Read payload.error then retry find_influencers.',
                'next_step' => 'Retry find_influencers after fixing the error.',
            ]);
        }

        if ($terminal || $suggestions !== []) {
            return Response::json([
                ...$base,
                'suggestions' => $suggestions,
                'queued' => false,
                'note' => 'Creators are NOT tracked yet. Each suggestion may include fit_reason and url. Call keep_influencer for each fit (queues sync) or discard_influencer.',
                'next_step' => 'keep_influencer / discard_influencer for each suggestion before ending the session.',
            ]);
        }

        return Response::json([
            ...$base,
            'queued' => true,
            'note' => 'Still running. Poll influencer_search_status (optionally with wait_seconds) until completed. Requires a queue worker.',
            'next_step' => 'Poll influencer_search_status, then keep_influencer / discard_influencer. Ensure php artisan queue:work is running.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->required(),
            'brief' => $schema->string()->required(),
            'language' => $schema->string()->nullable(),
            'min_followers' => $schema->integer()->nullable(),
            'max_followers' => $schema->integer()->nullable(),
            'wait_seconds' => $schema->integer()->nullable(),
        ];
    }
}
