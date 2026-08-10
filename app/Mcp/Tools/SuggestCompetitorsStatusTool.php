<?php

namespace App\Mcp\Tools;

use App\Jobs\SuggestCompetitorsJob;
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

#[Name('suggest_competitors_status')]
#[Description('Poll competitor suggestion status and rows. When suggestions appear, review them then call confirm_competitor_suggestions (required to track) or dismiss_competitor_suggestions. Status alone never adds TrackedAccounts.')]
class SuggestCompetitorsStatusTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'suggest_id' => ['required', 'string'],
        ]);

        if (! Str::isUuid($data['suggest_id'])) {
            return Response::error('Invalid suggest_id.');
        }

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $data['suggest_id']));
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        $runtime = McpRuntime::snapshot();

        $nextStep = 'Keep polling suggest_competitors_status until status is completed or failed.';
        $note = 'Still running - keep polling.';

        if (in_array($status, ['pending', 'queued', 'running'], true)
            && ($runtime['pending_jobs'] ?? 0) > 0) {
            $note = 'Job still pending/running with queued workers work. Ensure php artisan queue:work is running.';
            $nextStep = 'Start or verify a queue worker, then keep polling.';
        }

        if ($status === 'failed') {
            $note = 'Suggest run failed. Read payload.error, fix brand/credits/queue, then call suggest_competitors again.';
            $nextStep = 'Retry suggest_competitors after fixing the error.';
        }

        if ($status === 'completed' || $suggestions !== []) {
            $note = 'Suggestions are NOT tracked yet. Call confirm_competitor_suggestions with this suggest_id and selected handles, or dismiss_competitor_suggestions to clear.';
            $nextStep = 'confirm_competitor_suggestions (selected handles) or dismiss_competitor_suggestions. Do not end the session while suggestions are pending.';
        }

        return Response::json([
            'suggest_id' => $data['suggest_id'],
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
            'suggest_id' => $schema->string()->required(),
        ];
    }
}
