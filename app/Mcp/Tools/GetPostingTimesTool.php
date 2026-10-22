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

#[Name('get_posting_times')]
#[Description('Best posting-time slots from stored posts (Europe/London day-of-week + hour counts). Curator tokens may pass user_id. Does not scrape.')]
#[IsReadOnly]
class GetPostingTimesTool extends Tool
{
    public function handle(Request $request, McpReadService $reads): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        $data = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90'],
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
            ...$reads->postingTimes($user, (int) ($data['days'] ?? 30)),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Window: 7, 30, or 90. Default 30.')->nullable(),
            'user_id' => $schema->integer()->description('Curator only: another user id.')->nullable(),
        ];
    }
}
