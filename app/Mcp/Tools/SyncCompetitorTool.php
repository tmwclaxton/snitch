<?php

namespace App\Mcp\Tools;

use App\Jobs\SyncTrackedAccountJob;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Queue a sync for a tracked account. Apify usage is billed when the job runs.')]
class SyncCompetitorTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'tracked_account_id' => ['required', 'integer'],
        ]);

        $account = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->whereKey($data['tracked_account_id'])
            ->first();

        if ($account === null) {
            return Response::error('Tracked account not found.');
        }

        SyncTrackedAccountJob::dispatch($account->id, true);

        return Response::json(['queued' => true, 'tracked_account_id' => $account->id]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tracked_account_id' => $schema->integer()->required(),
        ];
    }
}
