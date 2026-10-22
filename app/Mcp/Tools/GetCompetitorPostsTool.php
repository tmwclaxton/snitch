<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Mcp\McpReadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_competitor_posts')]
#[Description('Competitor posts from stored data. Filter by handle, format (Reel/Carousel/Image), or since (Y-m-d). Sorted by times-usual descending. Curator tokens may pass user_id. Does not scrape.')]
#[IsReadOnly]
class GetCompetitorPostsTool extends Tool
{
    public function handle(Request $request, McpReadService $reads): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        $data = $request->validate([
            'handle' => ['nullable', 'string', 'max:100'],
            'format' => ['nullable', 'string', 'in:Reel,Carousel,Image,Story'],
            'since' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = McpAuth::subjectUser($actor, isset($data['user_id']) ? (int) $data['user_id'] : null);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user, $actor)) {
            return $blocked;
        }

        return Response::json([
            'user_id' => (int) $user->id,
            'posts' => $reads->competitorPosts($user, [
                'handle' => $data['handle'] ?? null,
                'format' => $data['format'] ?? null,
                'since' => $data['since'] ?? null,
                'limit' => $data['limit'] ?? 20,
                'sort' => 'times_usual',
            ]),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Competitor handle.')->nullable(),
            'format' => $schema->string()->enum(['Reel', 'Carousel', 'Image', 'Story'])->nullable(),
            'since' => $schema->string()->description('Only posts on or after this Y-m-d (Europe/London).')->nullable(),
            'limit' => $schema->integer()->description('Max posts (1-50). Default 20.')->nullable(),
            'user_id' => $schema->integer()->description('Curator only: another user id.')->nullable(),
        ];
    }
}
