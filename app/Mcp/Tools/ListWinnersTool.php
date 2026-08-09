<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List winner insights for the authenticated user.')]
class ListWinnersTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $winners = $user->winnerInsights()
            ->with('post:id,url,platform,caption,posted_at')
            ->orderByDesc('score')
            ->limit(30)
            ->get();

        return Response::json(['winners' => $winners]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
