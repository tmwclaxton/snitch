<?php

namespace App\Mcp\Tools;

use App\Jobs\AutofillBrandFromWebsiteJob;
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

#[Name('autofill_status')]
#[Description('Poll brand website autofill status. autofill_id optional - omit to use the latest autofill for this user. Optional wait_seconds (max 45; default 0) blocks briefly for a terminal status. When completed, fields are already saved on the brand profile - call get_brand then continue with competitors/influencers. Requires a queue worker.')]
class AutofillStatusTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'autofill_id' => ['nullable', 'string'],
            'wait_seconds' => ['nullable', 'integer', 'min:0', 'max:45'],
        ]);

        $autofillId = $data['autofill_id'] ?? null;
        if ($autofillId === null || $autofillId === '') {
            $latest = AutofillBrandFromWebsiteJob::latestCacheKeyFor($user->id);
            $autofillId = Cache::get($latest);
            if (! is_string($autofillId) || ! Str::isUuid($autofillId)) {
                return Response::error('No autofill_id provided and no latest brand autofill found. Call start_brand_autofill first.');
            }
        } elseif (! Str::isUuid($autofillId)) {
            return Response::error('Invalid autofill_id.');
        }

        $wait = McpJobWait::untilTerminal(
            AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId),
            isset($data['wait_seconds']) ? (int) $data['wait_seconds'] : 0,
            defaultSeconds: 0,
        );

        $payload = $wait['payload'];
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        $runtime = McpRuntime::snapshot();

        $nextStep = 'Keep polling autofill_status until completed or failed.';
        $note = 'Still running - keep polling.';

        if (in_array($status, ['pending', 'queued', 'running', 'processing'], true)
            && ($runtime['pending_jobs'] ?? 0) > 0) {
            $note = 'Autofill still queued/running. Ensure php artisan queue:work is running.';
            $nextStep = 'Start or verify a queue worker, then keep polling (optionally pass wait_seconds).';
        }

        if ($status === 'failed') {
            $note = 'Autofill failed. Read payload.error or call update_brand manually.';
            $nextStep = 'update_brand with name/website/description, then continue.';
        }

        if (in_array($status, ['completed', 'done'], true)) {
            $note = 'Brand autofill finished. Verify with get_brand before suggest_competitors / find_influencers.';
            $nextStep = 'get_brand, then suggest_competitors or find_influencers.';
        }

        return Response::json([
            'autofill_id' => $autofillId,
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
            'autofill_id' => $schema->string()->nullable(),
            'wait_seconds' => $schema->integer()->nullable(),
        ];
    }
}
