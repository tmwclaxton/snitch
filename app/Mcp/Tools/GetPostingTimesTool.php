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
#[Description('Best posting-time slots from stored posts (Europe/London day-of-week + hour counts). Does not scrape.')]
#[IsReadOnly]
class GetPostingTimesTool extends Tool
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
        ]);

        return Response::json($reads->postingTimes($user, (int) ($data['days'] ?? 30)));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Window: 7, 30, or 90. Default 30.')->nullable(),
        ];
    }
}
