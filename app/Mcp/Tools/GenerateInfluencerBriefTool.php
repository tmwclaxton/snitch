<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('generate_influencer_brief')]
#[Description('Generate an influencer search brief from brand context (NanoGPT; may be billable via find).')]
class GenerateInfluencerBriefTool extends Tool
{
    public function handle(Request $request, InfluencerDiscoveryService $discovery): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $brand = $user->brandProfile;
        if ($brand === null) {
            return Response::error('Create a brand profile first.');
        }

        $data = $request->validate([
            'platform' => ['nullable', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $filters = [
            'platforms' => isset($data['platform']) ? [$data['platform']] : [(string) config('snitch.influencer_find.default_platform', 'instagram')],
            'language' => null,
            'min_followers' => null,
            'max_followers' => null,
        ];

        if (! empty($data['notes'])) {
            $filters['notes'] = $data['notes'];
        }

        $brief = $discovery->generateBrief($brand, $filters);

        return Response::json(['brief' => $brief]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->nullable(),
            'notes' => $schema->string()->nullable(),
        ];
    }
}
