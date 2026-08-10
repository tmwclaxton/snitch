<?php

namespace App\Mcp\Tools;

use App\Jobs\FindInfluencersJob;
use App\Mcp\Support\McpAuth;
use App\Mcp\Support\McpJobWait;
use App\Mcp\Support\McpRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('influencer_search_status')]
#[Description('Poll influencer search status and suggestions. Optional wait_seconds (max 45; default 0) blocks briefly for a terminal status. When suggestions appear, call keep_influencer for fits (queues sync) or discard_influencer. Status alone never tracks creators.')]
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
            'wait_seconds' => ['nullable', 'integer', 'min:0', 'max:45'],
        ]);

        if (! Str::isUuid($data['run_id'])) {
            return Response::error('Invalid run_id.');
        }

        $wait = McpJobWait::untilTerminal(
            FindInfluencersJob::cacheKeyFor($user->id, $data['run_id']),
            isset($data['wait_seconds']) ? (int) $data['wait_seconds'] : 0,
            defaultSeconds: 0,
        );

        $payload = $wait['payload'];
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $runtime = McpRuntime::snapshot();

        $nextStep = 'Keep polling influencer_search_status until status is completed or failed.';
        $note = 'Still running - keep polling.';

        if (in_array($status, ['pending', 'queued', 'running', 'processing'], true)
            && ($runtime['pending_jobs'] ?? 0) > 0) {
            $note = 'Search still queued/running. Ensure php artisan queue:work is running.';
            $nextStep = 'Start or verify a queue worker, then keep polling (optionally pass wait_seconds).';
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
            'waited_seconds' => $wait['waited_seconds'],
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
            'wait_seconds' => $schema->integer()->nullable(),
        ];
    }
}
