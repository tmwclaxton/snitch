<?php

namespace App\Mcp\Tools;

use App\Jobs\AnalyzePostJob;
use App\Mcp\Support\McpAuth;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('analyze_post')]
#[Description('Queue NanoGPT video analysis for a post (billable).')]
class AnalyzePostTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'post_id' => ['required', 'integer'],
        ]);

        $post = Post::query()->where('user_id', $user->id)->whereKey($data['post_id'])->first();
        if ($post === null) {
            return Response::error('Post not found.');
        }

        AnalyzePostJob::dispatch($post->id);

        return Response::json(['queued' => true, 'post_id' => $post->id]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required(),
        ];
    }
}
