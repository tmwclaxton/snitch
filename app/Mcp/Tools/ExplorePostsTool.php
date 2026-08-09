<?php

namespace App\Mcp\Tools;

use App\Enums\AnalysisStatus;
use App\Mcp\Support\McpAuth;
use App\Models\AnalysisTerm;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('explore_posts')]
#[Description('Browse analysed posts and optional analysis terms for Explore.')]
class ExplorePostsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'platform' => ['nullable', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'include_terms' => ['nullable', 'boolean'],
        ]);

        $query = Post::query()
            ->where('user_id', $user->id)
            ->reelLike()
            ->whereHas('analysis', fn ($a) => $a->where('status', AnalysisStatus::Completed))
            ->with(['trackedAccount:id,handle,platform', 'analysis:id,post_id,status'])
            ->latest('posted_at')
            ->limit((int) ($data['limit'] ?? 20));

        if (! empty($data['platform'])) {
            $query->where('platform', $data['platform']);
        }

        $payload = [
            'posts' => $query->get(['id', 'platform', 'url', 'caption', 'posted_at', 'tracked_account_id']),
        ];

        if ($data['include_terms'] ?? false) {
            $payload['terms'] = AnalysisTerm::query()
                ->orderBy('dimension')
                ->orderBy('slug')
                ->limit(100)
                ->get(['id', 'dimension', 'slug', 'label']);
        }

        return Response::json($payload);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->nullable(),
            'limit' => $schema->integer()->nullable(),
            'include_terms' => $schema->boolean()->nullable(),
        ];
    }
}
