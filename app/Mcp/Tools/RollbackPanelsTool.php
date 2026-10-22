<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Dashboard\Panels\DashboardPanelService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rollback_panels')]
#[Description('Curator only. Restore a user dashboard panel set from a prior version snapshot.')]
class RollbackPanelsTool extends Tool
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
            'version' => ['required', 'integer', 'min:1'],
        ]);

        $subject = McpAuth::subjectUser($actor, (int) $data['user_id']);
        if ($subject instanceof Response) {
            return $subject;
        }

        try {
            $saved = $panels->rollbackForUser($subject, (int) $data['version'], $actor);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json([
            'user_id' => (int) $subject->id,
            'rolled_back_to' => (int) $data['version'],
            'panels' => $saved,
            'versions' => $panels->listVersions($subject),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Target user id.')->required(),
            'version' => $schema->integer()->description('Version number from get_panels.')->required(),
        ];
    }
}
