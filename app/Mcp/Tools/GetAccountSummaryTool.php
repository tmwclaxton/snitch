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

#[Name('get_account_summary')]
#[Description('Followers now, growth over 1/7/30 days plus since_first_snapshot (change and from_date), and last post for a tracked account. Omit handle for the own account; returns structured JSON with has_own_account when none is marked. Curator tokens may pass user_id. Stored data only.')]
#[IsReadOnly]
class GetAccountSummaryTool extends Tool
{
    public function handle(Request $request, McpReadService $reads): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        $data = $request->validate([
            'handle' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = McpAuth::subjectUser($actor, isset($data['user_id']) ? (int) $data['user_id'] : null);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user, $actor)) {
            return $blocked;
        }

        $summary = $reads->accountSummary($user, $data['handle'] ?? null);

        return Response::json([
            'user_id' => (int) $user->id,
            ...$summary,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()
                ->description('Tracked handle. Omit for the own account.')
                ->nullable(),
            'user_id' => $schema->integer()
                ->description('Curator only: read another user\'s account.')
                ->nullable(),
        ];
    }
}
