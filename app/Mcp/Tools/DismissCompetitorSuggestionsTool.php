<?php

namespace App\Mcp\Tools;

use App\Jobs\SuggestCompetitorsJob;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('dismiss_competitor_suggestions')]
#[Description('Clear a competitor suggestion run from cache without tracking anyone. Also clears the Competitors pending panel (latest + active pointers). Use after rejecting a run, or after confirm when leftover rows should disappear.')]
class DismissCompetitorSuggestionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'suggest_id' => ['required', 'string'],
        ]);

        if (! Str::isUuid($data['suggest_id'])) {
            return Response::error('Invalid suggest_id.');
        }

        SuggestCompetitorsJob::clearRun($user->id, $data['suggest_id']);

        return Response::json([
            'dismissed' => true,
            'next_step' => 'Pending suggestion panel cleared. list_competitors to see tracked rivals.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'suggest_id' => $schema->string()->required(),
        ];
    }
}
