<?php

namespace App\Mcp\Tools;

use App\Enums\TrackedAccountKind;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_influencers')]
#[Description('List kept influencer accounts (kind=influencer), including profile url, followers, and fit_reason when stored.')]
class ListInfluencersTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $rows = $user->trackedAccounts()
            ->where('kind', TrackedAccountKind::Influencer)
            ->orderBy('id')
            ->get([
                'id',
                'platform',
                'handle',
                'display_name',
                'url',
                'followers',
                'fit_reason',
                'last_synced_at',
                'last_sync_status',
            ]);

        return Response::json(['influencers' => $rows]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
