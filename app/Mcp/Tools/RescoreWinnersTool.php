<?php

namespace App\Mcp\Tools;

use App\Jobs\ScoreWinnersJob;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Queue winners rescoring for the authenticated user.')]
class RescoreWinnersTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $runId = ScoreWinnersJob::queueFor($user->id);

        return Response::json(['run_id' => $runId, 'queued' => true]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
