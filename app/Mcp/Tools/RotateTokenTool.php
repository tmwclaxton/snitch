<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Revoke existing MCP tokens and issue a new Sanctum API token.')]
class RotateTokenTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $user->tokens()->delete();
        $token = $user->createToken('mcp')->plainTextToken;

        return Response::json([
            'api_token' => $token,
            'mcp_url' => url('/mcp'),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
