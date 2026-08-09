<?php

namespace App\Mcp\Tools;

use App\Jobs\FindInfluencersJob;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Poll influencer search status and suggestions.')]
class InfluencerSearchStatusTool extends Tool
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

        return Response::json([
            'run_id' => $data['run_id'],
            'payload' => Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $data['run_id'])),
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
