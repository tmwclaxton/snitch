<?php

namespace App\Mcp\Tools;

use App\Jobs\ScoreWinnersJob;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rescore_winners_status')]
#[Description('Poll winners rescore status.')]
class RescoreWinnersStatusTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'run_id' => ['required', 'string'],
        ]);

        return Response::json([
            'run_id' => $data['run_id'],
            'payload' => Cache::get(ScoreWinnersJob::cacheKeyFor($user->id, $data['run_id'])),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->required(),
        ];
    }
}
