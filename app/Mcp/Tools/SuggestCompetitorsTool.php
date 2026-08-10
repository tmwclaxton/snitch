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

#[Name('suggest_competitors')]
#[Description('Queue AI competitor suggestions (Firecrawl + NanoGPT + Apify; billable).')]
class SuggestCompetitorsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($user->brandProfile === null) {
            return Response::error('Create a brand profile first.');
        }

        $suggestId = (string) Str::uuid();

        SuggestCompetitorsJob::beginRun($user->id, $suggestId);
        SuggestCompetitorsJob::dispatch($user->id, $suggestId);

        return Response::json([
            'suggest_id' => $suggestId,
            'queued' => true,
            'note' => 'Poll suggest_competitors_status with this suggest_id.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
