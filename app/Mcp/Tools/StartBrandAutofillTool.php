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

#[Name('start_brand_autofill')]
#[Description('Queue brand website autofill (Firecrawl + NanoGPT; billable). Optional wait_seconds (default ~22, max 45) polls cache so a completed autofill often returns in one call. Then get_brand before discovery.')]
class StartBrandAutofillTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'website' => ['required', 'url', 'max:2048'],
            'wait_seconds' => ['nullable', 'integer', 'min:0', 'max:45'],
        ]);

        $autofillId = (string) Str::uuid();
        Cache::put(AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId), [
            'status' => 'queued',
            'website' => $data['website'],
            'fields' => null,
            'error' => null,
        ], now()->addHour());

        AutofillBrandFromWebsiteJob::dispatch($user->id, $autofillId, $data['website']);

        $runtime = McpRuntime::snapshot();
        $wait = McpJobWait::untilTerminal(
            AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId),
            isset($data['wait_seconds']) ? (int) $data['wait_seconds'] : null,
        );

        $payload = $wait['payload'];
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        $terminal = McpJobWait::isTerminal($payload);

        $base = [
            'autofill_id' => $autofillId,
            'waited_seconds' => $wait['waited_seconds'],
            'payload' => $payload,
            'runtime' => [
                'app_url' => $runtime['app_url'],
                'pending_jobs' => $runtime['pending_jobs'],
                'warnings' => $runtime['warnings'],
            ],
        ];

        if ($status === 'failed') {
            return Response::json([
                ...$base,
                'queued' => false,
                'note' => 'Autofill failed. Read payload.error or call update_brand manually.',
                'next_step' => 'update_brand with name/website/description, then get_brand.',
            ]);
        }

        if ($terminal) {
            return Response::json([
                ...$base,
                'queued' => false,
                'note' => 'Brand autofill finished. Verify with get_brand before suggest_competitors / find_influencers.',
                'next_step' => 'get_brand, then suggest_competitors or find_influencers.',
            ]);
        }

        return Response::json([
            ...$base,
            'queued' => true,
            'note' => 'Still running. Poll autofill_status (optionally with wait_seconds). Requires a queue worker.',
            'next_step' => 'Poll autofill_status, then get_brand. Ensure php artisan queue:work is running.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->required(),
            'wait_seconds' => $schema->integer()->nullable(),
        ];
    }
}
