<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Enums\PostType;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Billing\VendorUsageCharger;
use App\Services\SnitchAnalyticsService;
use App\Support\SafeExceptionMessage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTrackedAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $trackedAccountId,
        public bool $force = false,
    ) {}

    public function handle(
        PlatformAdapterManager $adapters,
        SnitchAnalyticsService $analytics,
        VendorUsageCharger $charger,
    ): void {
        $account = TrackedAccount::query()->with('user')->find($this->trackedAccountId);

        if ($account === null) {
            return;
        }

        $owner = $account->user;

        if ($owner === null) {
            return;
        }

        if (! $this->force && ! $account->isDueForSync() && ! $account->isSyncing()) {
            Log::info('SyncTrackedAccountJob skipped; synced recently', [
                'tracked_account_id' => $this->trackedAccountId,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            ]);

            return;
        }

        try {
            $charger->assertCanRun($owner);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $e) {
            $account->fill([
                'last_sync_status' => 'failed',
                'last_sync_error' => $e->getMessage(),
            ])->save();

            Log::info('SyncTrackedAccountJob skipped; billing gate', [
                'tracked_account_id' => $this->trackedAccountId,
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $account->markSyncRunning();

        $limit = max(1, (int) config('snitch.sync.posts_limit', 12));
        $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));
        $cutoff = CarbonImmutable::now()->subDays($recencyDays);
        $scrapeDriver = $adapters->driverFor($account->platform);

        try {
            $adapter = $adapters->for($account->platform);

            if ($this->shouldResolveProfile($account)) {
                $profile = $adapter->resolveProfile($account->handle);

                $account->fill([
                    'url' => $profile['url'] ?: $account->url,
                    'external_id' => $profile['external_id'] ?? $account->external_id,
                    'avatar' => $profile['avatar'] ?? $account->avatar,
                    'display_name' => $profile['display_name'] ?? $account->display_name,
                ]);
            }

            $since = $this->syncSince($account, $recencyDays);
            $posts = $adapter->listRecentPosts($account->handle, $limit, $since);

            $existingPosts = Post::query()
                ->with('analysis')
                ->where('tracked_account_id', $account->id)
                ->get()
                ->keyBy('external_id');

            /** @var list<array<string, mixed>> $newPayloads */
            $newPayloads = [];

            foreach ($posts as $payload) {
                if (blank($payload['url'] ?? null)) {
                    continue;
                }

                $type = (string) ($payload['type'] ?? '');
                if (! in_array($type, PostType::analyzableValues(), true)) {
                    continue;
                }

                $postedAt = isset($payload['posted_at'])
                    ? CarbonImmutable::parse((string) $payload['posted_at'])
                    : null;

                if ($postedAt !== null && $postedAt->lt($cutoff)) {
                    continue;
                }

                $externalId = (string) ($payload['external_id'] ?? md5((string) $payload['url']));

                if ($existingPosts->has($externalId)) {
                    $this->dispatchAnalysisIfNeeded($existingPosts->get($externalId));

                    continue;
                }

                $payload['external_id'] = $externalId;
                $newPayloads[] = $payload;
            }

            $newPayloads = $adapter->hydrateMediaUrls($newPayloads);

            foreach ($newPayloads as $payload) {
                $mediaUrl = $payload['media_url'] ?? null;

                if (blank($mediaUrl)) {
                    continue;
                }

                $postedAt = isset($payload['posted_at'])
                    ? CarbonImmutable::parse((string) $payload['posted_at'])
                    : null;

                $post = Post::query()->create([
                    'tracked_account_id' => $account->id,
                    'external_id' => (string) $payload['external_id'],
                    'user_id' => $account->user_id,
                    'platform' => $account->platform,
                    'type' => (string) $payload['type'],
                    'url' => (string) $payload['url'],
                    'posted_at' => $postedAt?->toDateTimeString(),
                    'caption' => $payload['caption'] ?? null,
                    'media_url' => $mediaUrl,
                    'media_availability' => MediaAvailability::Available,
                    'unavailable_at' => null,
                    'unavailable_reason' => null,
                    'metrics' => $payload['metrics'] ?? [],
                    'raw_payload' => $payload['raw_payload'] ?? [],
                ]);

                $analytics->recordPostSynced($account->platform);

                $this->dispatchAnalysisIfNeeded($post->fresh('analysis'));
            }

            if ($scrapeDriver === 'tikhub') {
                $charger->chargePulledTikHubRuns($owner, 'sync.account');
            } else {
                $charger->chargePulledApifyRuns($owner, 'sync.account');
            }

            $account->fill([
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
            ])->save();

            ScoreWinnersJob::queueFor($account->user_id);
        } catch (Throwable $e) {
            $account->fill([
                'last_sync_status' => 'failed',
                'last_sync_error' => SafeExceptionMessage::forUsers($e, 'Sync failed.'),
            ])->save();

            Log::warning('SyncTrackedAccountJob failed', [
                'tracked_account_id' => $this->trackedAccountId,
                'error' => SafeExceptionMessage::forUsers($e, 'Sync failed.'),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $account = TrackedAccount::query()->find($this->trackedAccountId);

        if ($account === null || ! $account->isSyncing()) {
            return;
        }

        $account->fill([
            'last_sync_status' => 'failed',
            'last_sync_error' => SafeExceptionMessage::forUsers($e, 'Sync failed.'),
        ])->save();
    }

    private function shouldResolveProfile(TrackedAccount $account): bool
    {
        if ($this->force) {
            return true;
        }

        return blank($account->external_id)
            || blank($account->url)
            || blank($account->display_name);
    }

    private function syncSince(TrackedAccount $account, int $recencyDays): CarbonImmutable
    {
        $floor = CarbonImmutable::now()->subDays($recencyDays);

        if ($account->last_synced_at === null) {
            return $floor;
        }

        $withBuffer = CarbonImmutable::parse($account->last_synced_at->toIso8601String())->subDay();

        return $withBuffer->greaterThan($floor) ? $withBuffer : $floor;
    }

    private function dispatchAnalysisIfNeeded(Post $post): void
    {
        if (! $post->isAnalyzable()) {
            return;
        }

        if ($post->posted_at !== null) {
            $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));
            if ($post->posted_at->lt(now()->subDays($recencyDays))) {
                return;
            }
        }

        $status = $post->analysis?->status;

        if ($status === AnalysisStatus::Completed || $status === AnalysisStatus::Processing) {
            return;
        }

        if ($status === AnalysisStatus::Unavailable) {
            return;
        }

        // Do not burn queue/NanoGPT retries on the known YouTube page-URL gap.
        if ($status === AnalysisStatus::Failed && $post->youtubeMediaIsPageUrl()) {
            return;
        }

        // New posts, missing analysis, or failed analyses (soft retry).
        AnalyzePostJob::dispatch($post->id);
    }
}
