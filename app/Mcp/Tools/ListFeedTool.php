<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAppUrls;
use App\Mcp\Support\McpAuth;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_feed')]
#[Description('List recent reel-like posts for the authenticated user\'s tracked accounts.')]
class ListFeedTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $posts = Post::query()
            ->forUser($user)
            ->reelLike()
            ->with('socialAccount:id,handle,platform')
            ->latest('posted_at')
            ->limit((int) ($data['limit'] ?? 20))
            ->get(['id', 'platform', 'type', 'url', 'caption', 'posted_at', 'social_account_id']);

        $posts->each(fn (Post $post) => $post->makeHidden(['raw_payload']));
        McpAppUrls::attachSnitchUrls($posts);

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
