<?php

namespace App\Mcp\Tools;

use App\Enums\TrackedAccountKind;
use App\Jobs\SyncTrackedAccountJob;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Keep a discovered influencer as a tracked account and queue sync (billable).')]
class KeepInfluencerTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'platform' => ['required', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'handle' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ]);

        $account = TrackedAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'handle' => ltrim($data['handle'], '@'),
                'kind' => TrackedAccountKind::Influencer,
            ],
            [
                'external_id' => $data['external_id'] ?? null,
                'url' => $data['url'] ?? null,
                'display_name' => $data['display_name'] ?? null,
            ],
        );

        SyncTrackedAccountJob::dispatch($account->id, true);

        return Response::json(['tracked_account' => $account->only(['id', 'platform', 'handle', 'kind'])]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->required(),
            'handle' => $schema->string()->required(),
            'external_id' => $schema->string()->nullable(),
            'url' => $schema->string()->nullable(),
            'display_name' => $schema->string()->nullable(),
        ];
    }
}
