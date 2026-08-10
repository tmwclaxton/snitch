<?php

namespace App\Mcp\Tools;

use App\Jobs\FindInfluencersJob;
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

#[Name('influencer_search_status')]
#[Description('Poll influencer search status and suggestions. When suggestions appear, call keep_influencer for fits (queues sync) or discard_influencer. Status alone never tracks creators.')]
class InfluencerSearchStatusTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'run_id' => ['required', 'string'],
        ]);

        if (! Str::isUuid($data['run_id'])) {
            return Response::error('Invalid run_id.');
        }

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $data['run_id']));
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $runtime = McpRuntime::snapshot();

        $nextStep = 'Keep polling influencer_search_status until status is completed or failed.';
        $note = 'Still running - keep polling.';

        if (in_array($status, ['pending', 'queued', 'running'], true)
            && ($runtime['pending_jobs'] ?? 0) > 0) {
            $note = 'Search still queued/running. Ensure php artisan queue:work is running.';
            $nextStep = 'Start or verify a queue worker, then keep polling.';
        }

        if ($status === 'failed') {
            $note = 'Influencer search failed. Read payload.error then retry find_influencers.';
            $nextStep = 'Retry find_influencers after fixing the error.';
        }

        if ($status === 'completed' || $suggestions !== []) {
            $note = 'Creators are NOT tracked yet. Call keep_influencer for each fit (queues sync) or discard_influencer.';
            $nextStep = 'keep_influencer / discard_influencer for each suggestion before ending the session.';
        }

        return Response::json([
            'run_id' => $data['run_id'],
            'payload' => $payload,
            'note' => $note,
            'next_step' => $nextStep,
            'runtime' => [
                'pending_jobs' => $runtime['pending_jobs'],
                'warnings' => $runtime['warnings'],
            ],
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->required(),
        ];
    }
}
