<?php

namespace App\Mcp\Tools;

use App\Enums\TrackedAccountKind;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List tracked competitor accounts.')]
class ListCompetitorsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $rows = $user->trackedAccounts()
            ->where('kind', TrackedAccountKind::Competitor)
            ->orderBy('id')
            ->get(['id', 'platform', 'handle', 'display_name', 'url', 'last_synced_at', 'last_sync_status']);

        return Response::json(['competitors' => $rows]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
