<?php

namespace App\Mcp\Tools;

use App\Jobs\FindInfluencersJob;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('dismiss_influencer_suggestions')]
#[Description('Clear an influencer find run from cache without keeping anyone. Use after a shortlist/report workflow, or when rejecting a whole run. Prefer this over discarding every row one-by-one.')]
class DismissInfluencerSuggestionsTool extends Tool
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

        if (! Str::isUuid($data['run_id'])) {
            return Response::error('Invalid run_id.');
        }

        FindInfluencersJob::clearRun($user->id, $data['run_id']);

        return Response::json([
            'dismissed' => true,
            'next_step' => 'Suggestion shortlist cleared. list_influencers for kept accounts, or find_influencers to search again.',
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
