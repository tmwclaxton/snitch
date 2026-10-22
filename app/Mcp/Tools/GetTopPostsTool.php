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

#[Name('get_top_posts')]
#[Description('Highest times-usual competitor posts in a window (7, 30, or 90 days). Stored data only.')]
#[IsReadOnly]
class GetTopPostsTool extends Tool
{
    public function handle(Request $request, McpReadService $reads): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $days = (int) ($data['days'] ?? 30);
        $limit = (int) ($data['limit'] ?? 10);

        return Response::json([
            'days' => $days,
            'posts' => $reads->topPosts($user, $days, $limit),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Window: 7, 30, or 90. Default 30.')->nullable(),
            'limit' => $schema->integer()->description('Max posts (1-50). Default 10.')->nullable(),
        ];
    }
}
