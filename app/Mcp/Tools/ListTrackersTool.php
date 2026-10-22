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

#[Name('list_trackers')]
#[Description('List the authenticated user\'s tracked accounts (own + competitors). Curator tokens may pass user_id. Stored data only; does not scrape.')]
#[IsReadOnly]
class ListTrackersTool extends Tool
{
    public function handle(Request $request, McpReadService $reads): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        $data = $request->validate([
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
            'trackers' => $reads->listTrackers($user),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()
                ->description('Curator only: read another user\'s trackers.')
                ->nullable(),
        ];
    }
}
