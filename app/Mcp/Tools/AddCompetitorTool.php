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

#[Description('Add a competitor tracked account and queue a sync (billable).')]
class AddCompetitorTool extends Tool
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
            'sync' => ['nullable', 'boolean'],
        ]);

        $account = TrackedAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'handle' => ltrim($data['handle'], '@'),
                'kind' => TrackedAccountKind::Competitor,
            ],
            [],
        );

        if ($data['sync'] ?? true) {
            SyncTrackedAccountJob::dispatch($account->id, true);
        }

        return Response::json(['tracked_account' => $account->only(['id', 'platform', 'handle', 'kind'])]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->required(),
            'handle' => $schema->string()->required(),
            'sync' => $schema->boolean()->nullable(),
        ];
    }
}
