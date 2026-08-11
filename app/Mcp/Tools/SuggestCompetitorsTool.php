<?php

namespace App\Mcp\Tools;

use App\Enums\Platform;
use App\Jobs\SuggestCompetitorsJob;
use App\Mcp\Support\BrandContext;
use App\Mcp\Support\McpAuth;
use App\Mcp\Support\McpJobWait;
use App\Mcp\Support\McpRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('suggest_competitors')]
#[Description('Queue AI snitch suggestions - rivals or accounts whose style to copy (Firecrawl + NanoGPT + Apify; billable). Niche-first search: requires brand description or optional brief. Optional platforms array (instagram/tiktok/youtube/linkedin/facebook) to bias discovery (e.g. reel-native). Optional wait_seconds (default 0, max 45). Does NOT track anyone - after status completed you MUST call confirm_competitor_suggestions (or dismiss).')]
class SuggestCompetitorsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $platforms = array_map(fn (Platform $platform): string => $platform->value, Platform::cases());

        $data = $request->validate([
            'wait_seconds' => ['nullable', 'integer', 'min:0', 'max:45'],
            'platforms' => ['nullable', 'array', 'min:1'],
            'platforms.*' => ['required', 'string', Rule::in($platforms)],
            'brief' => ['nullable', 'string', 'min:8', 'max:5000'],
        ]);

        $brief = isset($data['brief']) ? trim((string) $data['brief']) : null;
        if ($brief === '') {
            $brief = null;
        }

        $blocked = BrandContext::assertReady($user, $brief);
        if ($blocked !== null) {
            return $blocked;
        }

        $filters = [];

        if (isset($data['platforms']) && is_array($data['platforms']) && $data['platforms'] !== []) {
            $filters['platforms'] = array_values(array_unique($data['platforms']));
        }

        if ($brief !== null) {
            $filters['brief'] = $brief;
            $brand = $user->brandProfile;
            if ($brand !== null) {
                $brand->forceFill([
                    'competitor_brief' => $brief,
                ])->save();
            }
        }

        $suggestId = (string) Str::uuid();
        $brandWarnings = BrandContext::warningsFor($user);
        $runtime = McpRuntime::snapshot();

        SuggestCompetitorsJob::beginRun($user->id, $suggestId, $filters);
        SuggestCompetitorsJob::dispatch($user->id, $suggestId, $filters);

        $wait = McpJobWait::untilTerminal(
            SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId),
            isset($data['wait_seconds']) ? (int) $data['wait_seconds'] : McpJobWait::QUEUED_TOOL_DEFAULT_SECONDS,
            defaultSeconds: McpJobWait::QUEUED_TOOL_DEFAULT_SECONDS,
        );

        return $this->responseForWait($suggestId, $wait, $brandWarnings, $runtime);
    }

    /**
     * @param  array{payload: mixed, timed_out: bool, waited_seconds: int}  $wait
     * @param  list<string>  $brandWarnings
     * @param  array<string, mixed>  $runtime
     */
    private function responseForWait(string $suggestId, array $wait, array $brandWarnings, array $runtime): Response
    {
        $payload = $wait['payload'];
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $terminal = McpJobWait::isTerminal($payload);

        $base = [
            'suggest_id' => $suggestId,
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
                'note' => 'Suggest run failed. Read payload.error, fix brand/credits/queue, then call suggest_competitors again.',
                'next_step' => 'Retry suggest_competitors after fixing the error.',
            ]);
        }

        if ($terminal || $suggestions !== []) {
            return Response::json([
                ...$base,
                'queued' => false,
                'note' => 'Suggestions are NOT tracked yet. Call confirm_competitor_suggestions with this suggest_id and selected handles, or dismiss_competitor_suggestions to clear.',
                'next_step' => 'confirm_competitor_suggestions (selected handles; dismiss_remainder defaults true) or dismiss_competitor_suggestions.',
            ]);
        }

        return Response::json([
            ...$base,
            'queued' => true,
            'note' => 'Still running. Poll suggest_competitors_status (optionally with wait_seconds) until completed or failed. Suggestions are NOT tracked until you call confirm_competitor_suggestions (or dismiss_competitor_suggestions). Requires a queue worker.',
            'next_step' => 'Poll suggest_competitors_status, then confirm_competitor_suggestions or dismiss_competitor_suggestions. Ensure php artisan queue:work is running.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'wait_seconds' => $schema->integer()->nullable(),
            'platforms' => $schema->array()->nullable(),
            'brief' => $schema->string()->nullable(),
        ];
    }
}
