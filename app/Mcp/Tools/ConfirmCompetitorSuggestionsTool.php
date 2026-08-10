<?php

namespace App\Mcp\Tools;

use App\Enums\TrackedAccountKind;
use App\Jobs\SuggestCompetitorsJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('confirm_competitor_suggestions')]
#[Description('REQUIRED after suggest_competitors to start tracking. Creates TrackedAccounts for selected handles from a suggest run and optionally queues syncs. Pass dismiss_remainder=true when you are done selecting so the Competitors pending panel clears (same as dismiss for leftover rows). Until confirmed, suggestions remain pending cache/UI only.')]
class ConfirmCompetitorSuggestionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'suggest_id' => ['required', 'string'],
            'handles' => ['required', 'array', 'min:1'],
            'handles.*' => ['required', 'string', 'max:255'],
            'sync' => ['nullable', 'boolean'],
            'dismiss_remainder' => ['nullable', 'boolean'],
        ]);

        if (! Str::isUuid($data['suggest_id'])) {
            return Response::error('Invalid suggest_id.');
        }

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $data['suggest_id']));
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $wanted = array_map(
            fn ($h) => strtolower(ltrim((string) $h, '@')),
            $data['handles'],
        );
        $created = [];
        $confirmed = [];

        foreach ($suggestions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $handle = ltrim((string) ($row['handle'] ?? ''), '@');
            if ($handle === '' || ! in_array(strtolower($handle), $wanted, true)) {
                continue;
            }

            $platform = (string) ($row['platform'] ?? 'instagram');

            $account = TrackedAccount::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'platform' => $platform,
                    'handle' => $handle,
                ],
                [
                    'kind' => TrackedAccountKind::Competitor,
                    'url' => $row['url'] ?? null,
                    'display_name' => $row['display_name'] ?? null,
                    'external_id' => $row['external_id'] ?? null,
                    'avatar' => $row['avatar'] ?? null,
                ],
            );
            $created[] = $account->id;
            $confirmed[] = [
                'platform' => $platform,
                'handle' => $handle,
            ];
            if ($data['sync'] ?? true) {
                SyncTrackedAccountJob::dispatch($account->id, true);
            }
        }

        if ($confirmed !== []) {
            SuggestCompetitorsJob::pruneSuggestions($user->id, $data['suggest_id'], $confirmed);
        }

        $dismissRemainder = (bool) ($data['dismiss_remainder'] ?? false);
        $remainingPayload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $data['suggest_id']));
        $remaining = is_array($remainingPayload['suggestions'] ?? null) ? $remainingPayload['suggestions'] : [];

        if ($dismissRemainder && $remaining !== []) {
            SuggestCompetitorsJob::clearRun($user->id, $data['suggest_id']);
            $remaining = [];
        }

        $nextStep = 'Done - confirmed handles are tracked.';
        if ($created === []) {
            $nextStep = 'No matches. Pass handles exactly as returned by suggest_competitors_status (case-insensitive).';
        } elseif ($remaining !== []) {
            $nextStep = 'Confirmed handles are tracked. Remaining suggestions still show on /competitors - call dismiss_competitor_suggestions or re-confirm with dismiss_remainder=true to clear the pending panel.';
        }

        return Response::json([
            'confirmed_ids' => $created,
            'remaining_count' => count($remaining),
            'note' => $created === []
                ? 'No matching suggestion handles. Pass handles exactly as returned by suggest_competitors_status.'
                : ($remaining === []
                    ? 'Confirmed handles are now tracked competitors. Pending suggestion panel is clear.'
                    : 'Confirmed handles are now tracked competitors. Remaining suggestion rows stay until dismiss_competitor_suggestions or confirm with dismiss_remainder=true.'),
            'next_step' => $nextStep,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'suggest_id' => $schema->string()->required(),
            'handles' => $schema->array()->required(),
            'sync' => $schema->boolean()->nullable(),
            'dismiss_remainder' => $schema->boolean()->nullable(),
        ];
    }
}
