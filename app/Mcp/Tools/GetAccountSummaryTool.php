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
#[Description('Followers now, growth over 1/7/30 days, and last post for a tracked account. Omit handle for the own account. Stored data only.')]
#[IsReadOnly]
class GetAccountSummaryTool extends Tool
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
            'handle' => ['nullable', 'string', 'max:100'],
        ]);

        $summary = $reads->accountSummary($user, $data['handle'] ?? null);
        if ($summary === null) {
            return Response::error('No tracked account found for that handle.');
        }

        return Response::json($summary);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()
                ->description('Tracked handle. Omit for the own account.')
                ->nullable(),
        ];
    }
}
