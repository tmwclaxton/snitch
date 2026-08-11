<?php

namespace App\Mcp\Tools;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Jobs\SyncTrackedAccountJob;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use App\Services\Billing\UsageBillingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('sync_competitor')]
#[Description('Queue a sync for a tracked account. Pass tracked_account_id (aliases: competitor_id, id) from list_competitors. Requires a credit balance above 20p; usage is billed when the job runs.')]
class SyncCompetitorTool extends Tool
{
    public function handle(Request $request, UsageBillingService $billing): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'tracked_account_id' => ['nullable', 'integer'],
            'competitor_id' => ['nullable', 'integer'],
            'id' => ['nullable', 'integer'],
        ]);

        $trackedAccountId = $data['tracked_account_id'] ?? $data['competitor_id'] ?? $data['id'] ?? null;

        if ($trackedAccountId === null) {
            return Response::error('tracked_account_id is required (aliases: competitor_id, id).');
        }

        $account = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->whereKey($trackedAccountId)
            ->first();

        if ($account === null) {
            return Response::error('Tracked account not found.');
        }

        try {
            $billing->assertCanRun($user);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $exception) {
            return Response::error($exception->getMessage());
        }

        $account->markSyncRunning();
        SyncTrackedAccountJob::dispatch($account->id, true);

        return Response::json(['queued' => true, 'tracked_account_id' => $account->id]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tracked_account_id' => $schema->integer()
                ->description('Tracked account id from list_competitors.id')
                ->nullable(),
            'competitor_id' => $schema->integer()
                ->description('Alias for tracked_account_id')
                ->nullable(),
            'id' => $schema->integer()
                ->description('Alias for tracked_account_id (same as list_competitors.id)')
                ->nullable(),
        ];
    }
}
