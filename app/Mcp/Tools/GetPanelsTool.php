<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Dashboard\Panels\DashboardPanelService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_panels')]
#[Description('Curator only. Return the stored dashboard panels for a user plus version history for rollback.')]
#[IsReadOnly]
class GetPanelsTool extends Tool
{
    public function handle(Request $request, DashboardPanelService $panels): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        if ($blocked = McpAuth::requireCurator($actor)) {
            return $blocked;
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        $subject = McpAuth::subjectUser($actor, (int) $data['user_id']);
        if ($subject instanceof Response) {
            return $subject;
        }

        return Response::json([
            'user_id' => (int) $subject->id,
            'panels' => $panels->listForUser($subject),
            'versions' => $panels->listVersions($subject),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Target user id.')->required(),
        ];
    }
}
