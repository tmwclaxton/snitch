<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Enums\PostType;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Services\Apify\PlatformAdapterManager;
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

    public function handle(PlatformAdapterManager $adapters): void
    {
        $account = TrackedAccount::query()->find($this->trackedAccountId);

        if ($account === null) {
            return;
        }

        if (! $this->force && ! $account->isDueForSync() && ! $account->isSyncing()) {
            Log::info('SyncTrackedAccountJob skipped; synced recently', [
                'tracked_account_id' => $this->trackedAccountId,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            ]);

            return;
        }

        $account->markSyncRunning();

        $limit = max(1, (int) config('snitch.sync.posts_limit', 12));
        $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));
        $cutoff = CarbonImmutable::now()->subDays($recencyDays);

        try {
            $adapter = $adapters->for($account->platform);
            $profile = $adapter->resolveProfile($account->handle);

            $account->fill([
                'url' => $profile['url'] ?: $account->url,
                'external_id' => $profile['external_id'] ?? $account->external_id,
                'avatar' => $profile['avatar'] ?? $account->avatar,
                'display_name' => $profile['display_name'] ?? $account->display_name,
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
            ])->save();

            $posts = $adapter->listRecentPosts($account->handle, $limit);

            foreach ($posts as $payload) {
                if (blank($payload['url'])) {
                    continue;
                }

                $type = (string) ($payload['type'] ?? '');
                $mediaUrl = $payload['media_url'] ?? null;

                if (! in_array($type, PostType::analyzableValues(), true) || blank($mediaUrl)) {
                    continue;
                }

                $postedAt = isset($payload['posted_at'])
                    ? CarbonImmutable::parse((string) $payload['posted_at'])
                    : null;

                if ($postedAt !== null && $postedAt->lt($cutoff)) {
                    continue;
                }

                $externalId = $payload['external_id'] ?? md5($payload['url']);

                $post = Post::query()->updateOrCreate(
                    [
                        'tracked_account_id' => $account->id,
                        'external_id' => $externalId,
                    ],
                    [
                        'user_id' => $account->user_id,
                        'platform' => $account->platform,
                        'type' => $type,
                        'url' => $payload['url'],
                        'posted_at' => $postedAt?->toDateTimeString(),
                        'caption' => $payload['caption'] ?? null,
                        'media_url' => $mediaUrl,
                        'media_availability' => MediaAvailability::Available,
                        'unavailable_at' => null,
                        'unavailable_reason' => null,
                        'metrics' => $payload['metrics'] ?? [],
                        'raw_payload' => $payload['raw_payload'] ?? [],
                    ],
                );

                $this->dispatchAnalysisIfNeeded($post->fresh('analysis'));
            }

            ScoreWinnersJob::queueFor($account->user_id);
        } catch (Throwable $e) {
            $account->fill([
                'last_sync_status' => 'failed',
                'last_sync_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            Log::warning('SyncTrackedAccountJob failed', [
                'tracked_account_id' => $this->trackedAccountId,
                'error' => $e->getMessage(),
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
            'last_sync_error' => mb_substr($e?->getMessage() ?? 'Sync failed.', 0, 1000),
        ])->save();
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
