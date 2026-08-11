<?php

namespace App\Mcp\Tools;

use App\Enums\TrackedAccountKind;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('remove_competitor')]
#[Description('Remove a tracked snitch account. Pass tracked_account_id (aliases: competitor_id, id) from list_competitors.')]
class RemoveCompetitorTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'tracked_account_id' => ['nullable', 'integer'],
            'competitor_id' => ['nullable', 'integer'],
            'id' => ['nullable', 'integer'],
        ]);

        $trackedAccountId = $data['tracked_account_id'] ?? $data['competitor_id'] ?? $data['id'] ?? null;

        if ($trackedAccountId === null) {
            return Response::error('tracked_account_id is required (aliases: competitor_id, id).');
        }

        $account = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('kind', TrackedAccountKind::Competitor)
            ->whereKey($trackedAccountId)
            ->first();

        if ($account === null) {
            return Response::error('Snitch account not found.');
        }

        $account->delete();

        return Response::json(['deleted' => true, 'tracked_account_id' => (int) $trackedAccountId]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tracked_account_id' => $schema->integer()
                ->description('Tracked snitch id from list_competitors.id')
                ->nullable(),
            'competitor_id' => $schema->integer()
                ->description('Alias for tracked_account_id')
                ->nullable(),
            'id' => $schema->integer()
                ->description('Alias for tracked_account_id (same as list_competitors.id)')
                ->nullable(),
        ];
    }
}
