<?php

namespace App\Mcp\Tools;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Jobs\SyncTrackedAccountJob;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use App\Services\Billing\UsageBillingService;
use App\Support\SyncOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('sync_competitor')]
#[Description('Queue a sync for a tracked account. Pass tracked_account_id (aliases: competitor_id, id) from list_competitors. Optional posts_limit and recency_days override defaults (config snitch.sync; capped). Requires a credit balance above 20p; usage is billed when the job runs.')]
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
            ...SyncOptions::optionalFieldRules(),
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

        $options = SyncOptions::fromValidated($data);

        try {
            $billing->assertCanRun($user);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $exception) {
            return Response::error($exception->getMessage());
        }

        $account->markSyncRunning();
        SyncTrackedAccountJob::dispatch(
            $account->id,
            force: true,
            postsLimit: $options->postsLimit,
            recencyDays: $options->recencyDays,
        );

        return Response::json([
            'queued' => true,
            'tracked_account_id' => $account->id,
            'posts_limit' => $options->resolvedPostsLimit(),
            'recency_days' => $options->resolvedRecencyDays(),
        ]);
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
            'posts_limit' => $schema->integer()
                ->description('Max reel-like posts to import (default config snitch.sync.posts_limit; max snitch.sync.posts_limit_max)')
                ->nullable(),
            'recency_days' => $schema->integer()
                ->description('How far back to look in days (default config snitch.sync.recency_days; max snitch.sync.recency_days_max)')
                ->nullable(),
        ];
    }
}
