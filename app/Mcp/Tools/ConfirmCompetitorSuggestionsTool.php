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
#[Description('Confirm competitor suggestions from a suggest run and optionally queue syncs.')]
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
        ]);

        if (! Str::isUuid($data['suggest_id'])) {
            return Response::error('Invalid suggest_id.');
        }

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $data['suggest_id']));
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $wanted = array_map(fn ($h) => ltrim((string) $h, '@'), $data['handles']);
        $created = [];

        foreach ($suggestions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $handle = ltrim((string) ($row['handle'] ?? ''), '@');
            if ($handle === '' || ! in_array($handle, $wanted, true)) {
                continue;
            }

            $account = TrackedAccount::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'platform' => (string) ($row['platform'] ?? 'instagram'),
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
            if ($data['sync'] ?? true) {
                SyncTrackedAccountJob::dispatch($account->id, true);
            }
        }

        return Response::json(['confirmed_ids' => $created]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'suggest_id' => $schema->string()->required(),
            'handles' => $schema->array()->required(),
            'sync' => $schema->boolean()->nullable(),
        ];
    }
}
