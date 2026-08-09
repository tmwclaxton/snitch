<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Return claim status and claim URL for the current agent account.')]
class ClaimInfoTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        return Response::json([
            'claimed' => $user->isClaimed(),
            'claimed_at' => $user->claimed_at?->toIso8601String(),
            'claim_url' => $user->claim_token ? url('/claim/'.$user->claim_token) : null,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
