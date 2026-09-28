<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Competitors\CompetitorAdsFinder;
use App\Services\SnitchAnalyticsService;
use App\Services\Tracking\AvatarMirror;
use App\Services\Tracking\FollowerCountRefresher;
use App\Services\Tracking\FollowerSnapshotRecorder;
use App\Services\Tracking\PostCoverHydrator;
use App\Support\InstagramMetrics;
use App\Support\InstagramPostId;
use App\Support\SafeExceptionMessage;
use App\Support\SyncOptions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
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
        PostCoverHydrator $covers = new PostCoverHydrator,
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

        $isFirstSync = $account->last_synced_at === null;
        $syncOptions = new SyncOptions($this->postsLimit, $this->recencyDays);
        $limit = $syncOptions->resolvedPostsLimit();
        $recencyDays = $syncOptions->resolvedRecencyDays();

        // Newly added trackers need a longer backfill so the dashboard has enough
        // posts for medians. Explicit job overrides still win.
        if ($isFirstSync && $this->postsLimit === null) {
            $firstLimit = max(1, (int) config('snitch.sync.first_sync_posts_limit', 50));
            $maxLimit = max($firstLimit, (int) config('snitch.sync.posts_limit_max', 50));
            $limit = min($firstLimit, $maxLimit);
        }

        if ($isFirstSync && $this->recencyDays === null) {
            $firstRecency = max(1, (int) config('snitch.sync.first_sync_recency_days', 90));
            $maxRecency = max($firstRecency, (int) config('snitch.sync.recency_days_max', 90));
            $recencyDays = min($firstRecency, $maxRecency);
        }

        $cutoff = CarbonImmutable::now()->subDays($recencyDays);
        $scrapeDriver = $adapters->driverFor($account->platform);
        $triedTikHub = $scrapeDriver === 'tikhub';

        try {
            $adapter = $adapters->for($account->platform);

            // Manual / force sync uses the full recency window. Incremental since
            // would skip real posts after an earlier empty scrape advanced last_synced_at.
            $since = $this->force
                ? CarbonImmutable::now()->subDays($recencyDays)
                : $this->syncSince($account, $recencyDays);

            try {
                $this->applyResolvedProfile($adapter, $account);
                $posts = $adapter->listRecentPosts($account->handle, $limit, $since);
            } catch (Throwable $scrapeFailure) {
                if ($scrapeDriver !== 'tikhub') {
                    throw $scrapeFailure;
                }

                Log::info('SyncTrackedAccountJob falling back to Apify after TikHub failure', [
                    'tracked_account_id' => $this->trackedAccountId,
                    'platform' => $account->platform->value,
                    'error' => SafeExceptionMessage::forUsers($scrapeFailure, 'TikHub scrape failed.'),
                ]);

                $adapter = $adapters->apifyAdapter($account->platform);
                $scrapeDriver = 'apify';
                $this->applyResolvedProfile($adapter, $account);
                $posts = $adapter->listRecentPosts($account->handle, $limit, $since);
            }

            if ($posts === [] && $scrapeDriver === 'tikhub') {
                Log::info('SyncTrackedAccountJob falling back to Apify after empty TikHub result', [
                    'tracked_account_id' => $this->trackedAccountId,
                    'platform' => $account->platform->value,
                ]);

                $adapter = $adapters->apifyAdapter($account->platform);
                $scrapeDriver = 'apify';
                $this->applyResolvedProfile($adapter, $account);
                $posts = $adapter->listRecentPosts($account->handle, $limit, $since);
            }

            // Apify sometimes finishes with an empty dataset (and $0 usage) while
            // TikHub still has reels. Fall back so sync does not "succeed" with nothing.
            // Do not bounce back to TikHub if we already tried it (cap 0 / prior 400).
            if ($posts === [] && $scrapeDriver === 'apify' && ! $triedTikHub) {
                $tikHubAdapter = $adapters->tikHubAdapter($account->platform);

                if ($tikHubAdapter !== null && filled(config('snitch.tikhub.api_key'))) {
                    Log::info('SyncTrackedAccountJob falling back to TikHub after empty Apify result', [
                        'tracked_account_id' => $this->trackedAccountId,
                        'platform' => $account->platform->value,
                    ]);

                    try {
                        $adapter = $tikHubAdapter;
                        $scrapeDriver = 'tikhub';
                        $posts = $adapter->listRecentPosts($account->handle, $limit, $since);
                    } catch (Throwable $tikHubEmptyFallback) {
                        Log::info('SyncTrackedAccountJob kept empty Apify result after TikHub fallback failed', [
                            'tracked_account_id' => $this->trackedAccountId,
                            'platform' => $account->platform->value,
                            'error' => SafeExceptionMessage::forUsers($tikHubEmptyFallback, 'TikHub scrape failed.'),
                        ]);
                        $posts = [];
                    }
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
                if (! in_array($type, self::importableTypes(), true)) {
                    continue;
                }

                $postedAt = isset($payload['posted_at'])
                    ? CarbonImmutable::parse((string) $payload['posted_at'])
                    : null;

                if ($postedAt !== null && $postedAt->lt($cutoff)) {
                    continue;
                }

                $externalId = (string) ($payload['external_id'] ?? md5((string) $payload['url']));
                $existing = $this->findExistingPost($existingPosts, $externalId, $payload, $account->platform);

                if ($existing instanceof Post) {
                    $this->refreshExistingPostMetrics($existing, $payload, $account);
                    $covers->persist($existing, fetchRemote: true, mapped: $payload);
                    $this->dispatchAnalysisIfNeeded($existing, (int) $account->user_id);

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
                    'metrics' => InstagramMetrics::normalizeMappedMetrics(
                        is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [],
                        is_numeric($account->followers) ? (int) $account->followers : null,
                    ),
                    'raw_payload' => $payload['raw_payload'] ?? [],
                ]);

                $analytics->recordPostSynced($account->platform);
                $covers->persist($post, fetchRemote: true);

                $this->dispatchAnalysisIfNeeded($post->fresh('analysis'), (int) $account->user_id);
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

            $postsInWindow = Post::query()
                ->where('social_account_id', $account->social_account_id)
                ->whereIn('type', self::importableTypes())
                ->where('posted_at', '>=', $cutoff)
                ->count();

            if ($postsInWindow === 0) {
                $account->fill([
                    'last_synced_at' => now(),
                    'last_sync_status' => 'empty',
                    'last_sync_error' => 'No recent posts found for this handle.',
                ])->save();
            } else {
                $account->fill([
                    'last_synced_at' => now(),
                    'last_sync_status' => 'success',
                    'last_sync_error' => null,
                ])->save();
            }

            app(FollowerSnapshotRecorder::class)->recordFromAccount($account->fresh() ?? $account);

            if ($account->followers !== null && $account->social_account_id !== null) {
                app(FollowerCountRefresher::class)->propagate(
                    (int) $account->social_account_id,
                    (int) $account->followers,
                );
            } elseif ($account->social_account_id !== null) {
                app(FollowerCountRefresher::class)->seedMissingTrackers((int) $account->social_account_id);
            }

            try {
                app(CompetitorAdsFinder::class)->refresh($account->fresh() ?? $account);
            } catch (Throwable $adsError) {
                Log::warning('Competitor ads refresh failed', [
                    'tracked_account_id' => $this->trackedAccountId,
                    'error' => SafeExceptionMessage::forUsers($adsError, 'Ads refresh failed.'),
                ]);
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

    /**
     * @return list<string>
     */
    private static function importableTypes(): array
    {
        return [
            ...PostType::analyzableValues(),
            PostType::Image->value,
            PostType::Carousel->value,
        ];
    }

    private function applyResolvedProfile(PlatformAdapter $adapter, TrackedAccount $account): void
    {
        if (! $this->shouldResolveProfile($account)) {
            if ($account->followers === null && $account->social_account_id !== null) {
                app(FollowerCountRefresher::class)->seedTracker($account);
            }

            $this->mirrorStoredAvatar($account);

            return;
        }

        $profile = $adapter->resolveProfile($account->handle);
        $followers = $this->followersFromProfile($profile);
        $remoteAvatar = filled($profile['avatar'] ?? null) ? (string) $profile['avatar'] : null;

        $account->fill([
            'url' => $profile['url'] ?: $account->url,
            'external_id' => $profile['external_id'] ?? $account->external_id,
            'display_name' => $profile['display_name'] ?? $account->display_name,
            ...($followers !== null ? ['followers' => $followers] : []),
        ]);

        if ($followers !== null && $account->social_account_id !== null) {
            app(FollowerCountRefresher::class)->propagate((int) $account->social_account_id, $followers);
        }

        app(AvatarMirror::class)->apply(
            $account,
            $account->socialAccount,
            $remoteAvatar,
        );
    }

    /**
     * Best-effort local mirror when sync skips resolveProfile (fields already
     * present). Uses avatar_source_url / remote avatar already on the row.
     */
    private function mirrorStoredAvatar(TrackedAccount $account): void
    {
        app(AvatarMirror::class)->apply(
            $account,
            $account->socialAccount,
            null,
        );
    }

    /**
     * @param  Collection<string, Post>  $existingPosts
     * @param  array<string, mixed>  $payload
     */
    private function findExistingPost(
        Collection $existingPosts,
        string $externalId,
        array $payload,
        Platform $platform,
    ): ?Post {
        $hit = $existingPosts->get($externalId);

        if ($hit instanceof Post) {
            return $hit;
        }

        if ($platform !== Platform::Instagram) {
            return null;
        }

        $shortcode = InstagramPostId::fromPayload($payload)
            ?? InstagramPostId::fromUrl((string) ($payload['url'] ?? ''));

        if ($shortcode === null || $shortcode === '') {
            return null;
        }

        foreach ($existingPosts as $post) {
            if (! $post instanceof Post) {
                continue;
            }

            if ((string) $post->external_id === $shortcode) {
                return $post;
            }

            $existingCode = InstagramPostId::fromUrl((string) $post->url);

            if ($existingCode !== null && $existingCode === $shortcode) {
                return $post;
            }
        }

        return null;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function refreshExistingPostMetrics(Post $post, array $payload, TrackedAccount $account): void
    {
        $incoming = $payload['metrics'] ?? null;

        if (! is_array($incoming) || $incoming === []) {
            return;
        }

        $metrics = InstagramMetrics::normalizeMappedMetrics(
            $incoming,
            is_numeric($account->followers) ? (int) $account->followers : null,
        );

        $current = is_array($post->metrics) ? $post->metrics : [];

        if ($current == $metrics) {
            return;
        }

        $post->forceFill(['metrics' => $metrics])->save();
    }

    private function dispatchAnalysisIfNeeded(Post $post, int $billingUserId): void
    {
        if (! $post->isAnalyzable()) {
            return;
        }

        $recencyDays = SyncOptions::analysisRecencyDays();

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
