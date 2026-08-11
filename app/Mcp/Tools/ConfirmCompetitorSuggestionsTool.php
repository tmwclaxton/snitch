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
#[Description('REQUIRED after suggest_competitors to start tracking. Creates TrackedAccounts for selected handles from a suggest run and optionally queues syncs. handles may be strings ("farmbrite") or {platform, handle} objects for platform-specific picks. dismiss_remainder defaults to true so leftover pending suggestion cards clear after a typical confirm; pass false to keep remainder. Until confirmed, suggestions remain pending cache/UI only.')]
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
            'sync' => ['nullable', 'boolean'],
            'dismiss_remainder' => ['nullable', 'boolean'],
        ]);

        if (! Str::isUuid($data['suggest_id'])) {
            return Response::error('Invalid suggest_id.');
        }

        $selectors = $this->normalizeHandleSelectors($data['handles']);
        if ($selectors === []) {
            return Response::error('handles must be non-empty strings or {platform, handle} objects.');
        }

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $data['suggest_id']));
        if (! is_array($payload)) {
            return Response::error('Suggest run not found. Call suggest_competitors_status with this suggest_id first.');
        }

        $runStatus = is_string($payload['status'] ?? null) ? (string) $payload['status'] : null;
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $created = [];
        $confirmed = [];
        $midRunWarning = in_array($runStatus, ['queued', 'pending', 'processing', 'running'], true)
            ? 'Suggest run status is still '.$runStatus.'. Partial rows may include weak matches - prefer waiting until completed before confirming.'
            : null;

        foreach ($suggestions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $handle = ltrim((string) ($row['handle'] ?? ''), '@');
            $platform = (string) ($row['platform'] ?? 'instagram');
            if ($handle === '' || ! $this->rowMatchesSelectors($platform, $handle, $selectors)) {
                continue;
            }

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

        $dismissRemainder = (bool) ($data['dismiss_remainder'] ?? true);
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
            $nextStep = 'Confirmed handles are tracked. Remaining suggestions still show on /competitors - call dismiss_competitor_suggestions or re-confirm (dismiss_remainder defaults true; pass false only when you want to keep remainder).';
        }

        return Response::json([
            'confirmed_ids' => $created,
            'remaining_count' => count($remaining),
            'dismiss_remainder' => $dismissRemainder,
            'run_status' => $runStatus,
            'warning' => $midRunWarning,
            'note' => $created === []
                ? 'No matching suggestion handles. Pass handles exactly as returned by suggest_competitors_status.'
                : ($remaining === []
                    ? 'Confirmed handles are now tracked snitches. Pending suggestion panel is clear.'
                    : 'Confirmed handles are now tracked snitches. Remaining suggestion rows stay because dismiss_remainder=false.'),
            'next_step' => $nextStep,
        ]);
    }

    /**
     * @param  list<mixed>  $handles
     * @return list<array{handle: string, platform: ?string}>
     */
    private function normalizeHandleSelectors(array $handles): array
    {
        $selectors = [];

        foreach ($handles as $entry) {
            if (is_string($entry)) {
                $handle = strtolower(ltrim($entry, '@'));
                if ($handle !== '') {
                    $selectors[] = ['handle' => $handle, 'platform' => null];
                }

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $handle = strtolower(ltrim((string) ($entry['handle'] ?? ''), '@'));
            if ($handle === '') {
                continue;
            }

            $platform = isset($entry['platform']) ? strtolower(trim((string) $entry['platform'])) : null;
            $selectors[] = [
                'handle' => $handle,
                'platform' => $platform !== '' ? $platform : null,
            ];
        }

        return $selectors;
    }

    /**
     * @param  list<array{handle: string, platform: ?string}>  $selectors
     */
    private function rowMatchesSelectors(string $platform, string $handle, array $selectors): bool
    {
        $handleKey = strtolower(ltrim($handle, '@'));
        $platformKey = strtolower($platform);

        foreach ($selectors as $selector) {
            if ($selector['handle'] !== $handleKey) {
                continue;
            }

            if ($selector['platform'] === null || $selector['platform'] === $platformKey) {
                return true;
            }
        }

        return false;
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
