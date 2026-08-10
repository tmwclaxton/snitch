<?php

namespace App\Mcp\Tools;

use App\Jobs\FindInfluencersJob;
use App\Mcp\Support\BrandContext;
use App\Mcp\Support\McpAuth;
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
#[Description('Queue influencer discovery for one platform (billable). Does NOT track anyone. After polling influencer_search_status, call keep_influencer for fits (queues sync) or discard_influencer. Requires a queue worker.')]
class FindInfluencersTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($user->brandProfile === null) {
            return Response::error('Create a brand profile first.');
        }

        $data = $request->validate([
            'platform' => ['required', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'brief' => ['required', 'string', 'max:5000'],
            'language' => ['nullable', 'string', 'max:32'],
            'min_followers' => ['nullable', 'integer', 'min:0'],
            'max_followers' => ['nullable', 'integer', 'min:0'],
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

        return Response::json([
            'run_id' => $runId,
            'queued' => true,
            'brand_warnings' => $brandWarnings,
            'runtime' => [
                'app_url' => $runtime['app_url'],
                'pending_jobs' => $runtime['pending_jobs'],
                'warnings' => $runtime['warnings'],
            ],
            'note' => 'Poll influencer_search_status until completed. Creators are NOT tracked until keep_influencer.',
            'next_step' => 'Poll influencer_search_status, then keep_influencer / discard_influencer. Requires a queue worker.',
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
        ];
    }
}
