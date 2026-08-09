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

#[Description('Discard an influencer suggestion from an active find run.')]
class DiscardInfluencerTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'run_id' => ['required', 'string'],
            'platform' => ['required', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'handle' => ['required', 'string', 'max:255'],
        ]);

        if (! Str::isUuid($data['run_id'])) {
            return Response::error('Invalid run_id.');
        }

        $key = FindInfluencersJob::cacheKeyFor($user->id, $data['run_id']);
        $payload = Cache::get($key);
        if (! is_array($payload)) {
            return Response::error('Run not found.');
        }

        $handle = ltrim($data['handle'], '@');
        $decisionKey = $data['platform'].':'.$handle;
        $decisions = is_array($payload['decisions'] ?? null) ? $payload['decisions'] : [];
        $decisions[$decisionKey] = 'discarded';
        $payload['decisions'] = $decisions;
        Cache::put($key, $payload, now()->addHours(2));

        return Response::json(['discarded' => true, 'decision_key' => $decisionKey]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->required(),
            'platform' => $schema->string()->required(),
            'handle' => $schema->string()->required(),
        ];
    }
}
