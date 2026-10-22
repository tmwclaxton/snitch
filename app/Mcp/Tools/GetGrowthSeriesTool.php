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

#[Name('get_growth_series')]
#[Description('Follower and engagement growth series from stored snapshots (30d, 90d, or all). Does not scrape.')]
#[IsReadOnly]
class GetGrowthSeriesTool extends Tool
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
            'period' => ['nullable', 'string', 'in:30d,90d,all'],
            'accounts' => ['nullable', 'array', 'max:50'],
            'accounts.*' => ['integer'],
        ]);

        $selected = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            is_array($data['accounts'] ?? null) ? $data['accounts'] : [],
        ), static fn (int $id): bool => $id > 0));

        return Response::json($reads->growthSeries(
            $user,
            $data['period'] ?? '30d',
            $selected,
        ));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->enum(['30d', '90d', 'all'])->nullable(),
            'accounts' => $schema->array()
                ->description('Optional tracked account ids to narrow the series.')
                ->nullable(),
        ];
    }
}
