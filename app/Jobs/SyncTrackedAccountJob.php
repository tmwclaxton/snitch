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
use App\Support\SyncOptions;
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
        public ?int $postsLimit = null,
        public ?int $recencyDays = null,
    ) {}

    public function handle(
        PlatformAdapterManager $adapters,
        SnitchAnalyticsService $analytics,
        VendorUsageCharger $charger,
    ): void {
        $account = TrackedAccount::query()->with(['user', 'socialAccount'])->find($this->trackedAccountId);

        if ($account === null) {
            return;
        }

        $owner = $account->user;

        if ($owner === null) {
            return;
        }

        if ($account->social_account_id === null) {
            Log::warning('SyncTrackedAccountJob skipped; missing social_account_id', [
                'tracked_account_id' => $this->trackedAccountId,
            ]);

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

        $syncOptions = new SyncOptions($this->postsLimit, $this->recencyDays);
        $limit = $syncOptions->resolvedPostsLimit();
        $recencyDays = $syncOptions->resolvedRecencyDays();
        $cutoff = CarbonImmutable::now()->subDays($recencyDays);
        $scrapeDriver = $adapters->driverFor($account->platform);

        try {
            $adapter = $adapters->for($account->platform);

            if ($this->shouldResolveProfile($account)) {
                $profile = $adapter->resolveProfile($account->handle);
                $followers = $this->followersFromProfile($profile);

                $account->fill([
                    'url' => $profile['url'] ?: $account->url,
                    'external_id' => $profile['external_id'] ?? $account->external_id,
                    'avatar' => $profile['avatar'] ?? $account->avatar,
                    'display_name' => $profile['display_name'] ?? $account->display_name,
                    ...($followers !== null ? ['followers' => $followers] : []),
                ]);
            }

            // Manual / force sync uses the full recency window. Incremental since
            // would skip real posts after an earlier empty scrape advanced last_synced_at.
            $since = $this->force
                ? CarbonImmutable::now()->subDays($recencyDays)
                : $this->syncSince($account, $recencyDays);
            $posts = $adapter->listRecentPosts($account->handle, $limit, $since);

            // Apify sometimes finishes with an empty dataset (and $0 usage) while
            // TikHub still has reels. Fall back so sync does not "succeed" with nothing.
            if ($posts === [] && $scrapeDriver === 'apify') {
                $tikHubAdapter = $adapters->tikHubAdapter($account->platform);

                if ($tikHubAdapter !== null && filled(config('snitch.tikhub.api_key'))) {
                    Log::info('SyncTrackedAccountJob falling back to TikHub after empty Apify result', [
                        'tracked_account_id' => $this->trackedAccountId,
                        'platform' => $account->platform->value,
                    ]);

                    $adapter = $tikHubAdapter;
                    $scrapeDriver = 'tikhub';
                    $posts = $adapter->listRecentPosts($account->handle, $limit, $since);
                }
            }

            $existingPosts = Post::query()
                ->with('analysis')
                ->where('social_account_id', $account->social_account_id)
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
                    $this->dispatchAnalysisIfNeeded($existingPosts->get($externalId), (int) $account->user_id, $recencyDays);

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

                // YouTube shorts often omit published_time until hydrate backfills
                // it. Drop archive dates so they never become unanalyzable backlog ghosts.
                if ($postedAt !== null && $postedAt->lt($cutoff)) {
                    continue;
                }

                $post = Post::query()->create([
                    'social_account_id' => $account->social_account_id,
                    'external_id' => (string) $payload['external_id'],
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

                $this->dispatchAnalysisIfNeeded($post->fresh('analysis'), (int) $account->user_id, $recencyDays);
            }

            // Pull both buffers: empty Apify→TikHub fallback and YouTube
            // TikHub hydrate can leave costs on either client in one job.
            $syncMeta = [
                'tracked_account_id' => $account->id,
                'platform' => $account->platform?->value,
                'handle' => $account->handle,
                'account_kind' => $account->kind?->value,
            ];
            $charger->chargePulledApifyRuns($owner, 'sync.account', $syncMeta);
            $charger->chargePulledTikHubRuns($owner, 'sync.account', $syncMeta);

            $reelsInWindow = Post::query()
                ->where('social_account_id', $account->social_account_id)
                ->reelLike()
                ->where('posted_at', '>=', $cutoff)
                ->count();

            if ($reelsInWindow === 0) {
                $account->fill([
                    'last_synced_at' => now(),
                    'last_sync_status' => 'empty',
                    'last_sync_error' => 'No recent reels found for this handle.',
                ])->save();
            } else {
                $account->fill([
                    'last_synced_at' => now(),
                    'last_sync_status' => 'success',
                    'last_sync_error' => null,
                ])->save();
            }

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

    /**
     * @param  array<string, mixed>  $profile
     */
    private function followersFromProfile(array $profile): ?int
    {
        if (! isset($profile['followers']) || ! is_numeric($profile['followers'])) {
            return null;
        }

        return max(0, (int) $profile['followers']);
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

    private function dispatchAnalysisIfNeeded(Post $post, int $billingUserId, int $recencyDays): void
    {
        if (! $post->isAnalyzable()) {
            return;
        }

        if ($post->posted_at !== null) {
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

        // New posts, missing analysis, or failed analyses (soft retry).
        // YouTube page-URL failures can succeed after TikHub media hydration in AnalyzePostJob.
        AnalyzePostJob::dispatch($post->id, $billingUserId);
    }
}
