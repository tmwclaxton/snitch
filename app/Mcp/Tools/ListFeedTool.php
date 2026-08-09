<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List recent synced posts for the authenticated user.')]
class ListFeedTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $posts = Post::query()
            ->where('user_id', $user->id)
            ->orderByDesc('posted_at')
            ->limit((int) ($data['limit'] ?? 20))
            ->get(['id', 'platform', 'type', 'url', 'caption', 'posted_at', 'tracked_account_id']);

        return Response::json(['posts' => $posts]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->nullable(),
        ];
    }
}
