<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Enums\Platform;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Services\Analysis\VideoAnalysisService;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Scraping\YoutubeMediaHydrator;
use App\Services\Winners\WinnerScorer;
use App\Support\PublicDiskMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzePostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $postId) {}

    public function handle(
        VideoAnalysisService $analysis,
        WinnerScorer $scorer,
        VendorUsageCharger $charger,
        UsageBillingService $billing,
        YoutubeMediaHydrator $youtubeMedia,
    ): void {
        $post = Post::query()->with(['analysis', 'user'])->find($this->postId);

        if ($post === null) {
            return;
        }

        if ($post->media_availability === MediaAvailability::Unavailable) {
            return;
        }

        if (! $post->isAnalyzable()) {
            return;
        }

        $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));
        if ($post->posted_at !== null && $post->posted_at->lt(now()->subDays($recencyDays))) {
            return;
        }

        $owner = $post->user;

        if ($owner === null) {
            return;
        }

        try {
            $charger->assertCanRun($owner);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $e) {
            $this->markAnalysisFailed($post, $e->getMessage());

            return;
        }

        if ($this->mediaLooksGone($post)) {
            $this->markUnavailable($post, 'Media URL returned 403/404 or empty response.');

            return;
        }

        // Shorts often have page URLs or IP-bound googlevideo links; persist a public MP4 first.
        // channel_shorts also often returns empty published_time; backfill from web_v2 metadata.
        if ($post->platform === Platform::Youtube) {
            $youtubeUpdates = [];

            if ($youtubeMedia->needsHydration($post->media_url)) {
                $downloadUrl = $youtubeMedia->resolveDownloadUrl(
                    url: $post->url,
                    videoId: $post->external_id,
                    existingMediaUrl: $post->media_url,
                );

                if ($downloadUrl === null) {
                    $this->markAnalysisFailed(
                        $post,
                        'YouTube Shorts analysis needs a downloadable MP4; actor returned a page URL.',
                    );

                    return;
                }

                $youtubeUpdates['media_url'] = $downloadUrl;
            }

            if ($post->posted_at === null) {
                $postedAt = $youtubeMedia->resolvePostedAt($post->external_id, $post->url);

                if ($postedAt !== null) {
                    $youtubeUpdates['posted_at'] = $postedAt;
                }
            }

            if ($youtubeUpdates !== []) {
                $post->forceFill($youtubeUpdates)->save();
                $charger->chargePulledTikHubRuns($owner, 'analyze.post', $this->chargeMeta($post));
                $post->refresh();
            }
        }

        try {
            $outcome = $analysis->analyzePost($post);
            $persisted = $outcome['analysis'];

            $cogs = $billing->estimateNanoGptChatUsd(
                inputTokens: $outcome['prompt_tokens'],
                outputTokens: $outcome['completion_tokens'],
                model: (string) config('snitch.video_analysis.model'),
                floorKey: 'video_analysis',
            );
            $charger->chargeNanoGpt(
                user: $owner,
                action: 'analyze.post',
                cogsUsd: $cogs,
                meta: [
                    ...$this->chargeMeta($post),
                    'prompt_tokens' => $outcome['prompt_tokens'],
                    'completion_tokens' => $outcome['completion_tokens'],
                ],
                idempotencyKey: 'analyze.post:'.$post->id.':'.$persisted->id,
            );

            $scorer->scoreAndPersist($post->fresh('analysis'));

            if ($persisted->status === AnalysisStatus::Completed) {
                EmbedPostAnalysisJob::dispatch($persisted->id);
            }
        } catch (Throwable $e) {
            if ($this->isUnavailableException($e)) {
                $this->markUnavailable($post, $e->getMessage());

                return;
            }

            Log::warning('AnalyzePostJob failed', [
                'post_id' => $this->postId,
                'error' => $e->getMessage(),
            ]);

            // Checklist / validation failures already persist Failed on the analysis row.
            // Do not burn queue retries on the same model output.
            if (str_starts_with($e->getMessage(), 'Analysis failed checklist:')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * @return array{post_id: int, tracked_account_id: int|null, platform: string|null, post_type: string|null}
     */
    private function chargeMeta(Post $post): array
    {
        return [
            'post_id' => $post->id,
            'tracked_account_id' => $post->tracked_account_id,
            'platform' => $post->platform instanceof Platform ? $post->platform->value : null,
            'post_type' => $post->type?->value,
        ];
    }

    private function mediaLooksGone(Post $post): bool
    {
        $mediaUrl = (string) $post->media_url;

        if ($mediaUrl === '' || ! str_starts_with($mediaUrl, 'http')) {
            return true;
        }

        // App-owned public-disk copies (YouTube hydrate) must not depend on
        // HTTP HEAD against APP_URL. Missing public/storage symlink returns 403
        // and was falsely marking Shorts unavailable before NanoGPT ran.
        if (PublicDiskMedia::relativePathFromUrl($mediaUrl) !== null) {
            return ! PublicDiskMedia::existsOnPublicDisk($mediaUrl);
        }

        // YouTube page URLs are not HEAD-checkable the same way; skip probe.
        if ($post->platform?->value === 'youtube' && str_contains($mediaUrl, 'youtube.com')) {
            return false;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'SnitchMediaProbe/1.0'])
                ->head($mediaUrl);

            if (in_array($response->status(), [401, 403, 404, 410], true)) {
                return true;
            }

            if ($response->successful()) {
                return false;
            }

            // Some CDNs reject HEAD; try a tiny GET range.
            $get = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'SnitchMediaProbe/1.0',
                    'Range' => 'bytes=0-0',
                ])
                ->get($mediaUrl);

            return in_array($get->status(), [401, 403, 404, 410], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function markUnavailable(Post $post, string $reason): void
    {
        $post->markUnavailable($reason);

        $analysis = PostAnalysis::query()->firstOrNew(['post_id' => $post->id]);
        $analysis->fill([
            'status' => AnalysisStatus::Unavailable,
            'error_message' => $reason,
        ]);
        $analysis->save();
    }

    private function markAnalysisFailed(Post $post, string $reason): void
    {
        $analysis = PostAnalysis::query()->firstOrNew(['post_id' => $post->id]);
        $analysis->fill([
            'status' => AnalysisStatus::Failed,
            'error_message' => $reason,
        ]);
        $analysis->save();
    }

    private function isUnavailableException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, '403')
            || str_contains($message, '404')
            || str_contains($message, '410')
            || str_contains($message, 'expired')
            || str_contains($message, 'not available')
            || str_contains($message, 'unavailable')
            || str_contains($message, 'private');
    }
}
