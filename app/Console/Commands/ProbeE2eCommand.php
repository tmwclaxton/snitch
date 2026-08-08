<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\VideoAnalysisService;
use App\Services\Winners\WinnerScorer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:probe-e2e {--user=} {--handle=} {--platform=instagram}')]
#[Description('End-to-end Snitch probe: brand, sync (recency/reels), analyzePost, winners')]
class ProbeE2eCommand extends Command
{
    public function handle(
        VideoAnalysisService $analysis,
        WinnerScorer $scorer,
    ): int {
        if (! filter_var((string) env('SNITCH_LIVE_E2E', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('SNITCH_LIVE_E2E is not enabled. Skipping live e2e probe.');

            return self::SUCCESS;
        }

        $email = (string) $this->option('user');
        $handle = ltrim((string) $this->option('handle'), '@');
        $platform = Platform::tryFrom((string) $this->option('platform'));

        if ($email === '' || $handle === '') {
            $this->error('--user and --handle are required for live e2e.');

            return self::FAILURE;
        }

        if ($platform === null) {
            $this->error('Invalid --platform. Use tiktok, instagram, facebook, linkedin, or youtube.');

            return self::FAILURE;
        }

        $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));
        $postsLimit = max(1, (int) config('snitch.sync.posts_limit', 12));
        $cutoff = CarbonImmutable::now()->subDays($recencyDays);

        $this->info('E2E assumptions:');
        $this->line('- Reel/short-video only (types: '.implode(', ', PostType::analyzableValues()).')');
        $this->line("- Sync recency: last {$recencyDays} days; posts_limit={$postsLimit}");
        $this->line('- Concept-first analysis via VideoAnalysisService::analyzePost (persists PostAnalysis)');
        $this->line('- Sync uses dispatchSync here; app async jobs need a queue worker (QUEUE_CONNECTION=database)');
        $this->line('- YouTube Shorts: sync OK; analysis needs downloadable MP4 (page URLs are a known gap)');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        BrandProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name,
                'description' => 'E2E probe brand',
                'own_handles' => [],
            ],
        );

        $account = TrackedAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'handle' => $handle,
            ],
            [
                'url' => $this->defaultUrl($platform, $handle),
                'display_name' => $handle,
            ],
        );

        SyncTrackedAccountJob::dispatchSync($account->id, force: true);

        $account->refresh();

        if ($account->last_sync_status === 'failed') {
            $this->error('Sync failed: '.($account->last_sync_error ?: 'unknown error'));

            return self::FAILURE;
        }

        $posts = Post::query()
            ->where('tracked_account_id', $account->id)
            ->reelLike()
            ->mediaAvailable()
            ->whereNotNull('media_url')
            ->orderByDesc('posted_at')
            ->get();

        if ($posts->isEmpty()) {
            $this->error('No reel-like posts with media_url after sync (last '.$recencyDays.' days).');

            return self::FAILURE;
        }

        $stale = $posts->first(
            fn (Post $post): bool => $post->posted_at !== null && $post->posted_at->lt($cutoff),
        );

        if ($stale !== null) {
            $this->error("Post {$stale->id} is older than the {$recencyDays}-day recency cap.");

            return self::FAILURE;
        }

        $nonReel = $posts->first(
            fn (Post $post): bool => ! ($post->type instanceof PostType) || ! $post->type->isReelLike(),
        );

        if ($nonReel !== null) {
            $this->error("Post {$nonReel->id} is not reel/video (got {$nonReel->type?->value}).");

            return self::FAILURE;
        }

        $post = $posts->first();
        $this->info("Synced {$posts->count()} reel-like post(s); analyzing post {$post->id}");

        if ($post->youtubeMediaIsPageUrl()) {
            $this->warn('KNOWN GAP: YouTube Shorts sync imported a page URL, not a downloadable MP4.');
            $this->warn('NanoGPT analysis cannot run until the actor (or a follow-up fetch) returns a file URL.');
            $this->info('E2E sync assertions passed; analysis skipped for known YouTube media gap.');

            return self::SUCCESS;
        }

        try {
            $analysis->analyzePost($post->fresh());
        } catch (\Throwable $e) {
            $this->error('analyzePost failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $persisted = PostAnalysis::query()->where('post_id', $post->id)->first();

        if ($persisted === null || $persisted->status !== AnalysisStatus::Completed) {
            $this->error('PostAnalysis was not persisted as completed.');

            return self::FAILURE;
        }

        foreach (['concept', 'hook', 'idea', 'how_to_copy'] as $field) {
            if (blank($persisted->{$field})) {
                $this->error("PostAnalysis missing concept-first field: {$field}");

                return self::FAILURE;
            }
        }

        $insight = $scorer->scoreAndPersist($post->fresh(['analysis', 'user']));

        $this->info('Winner report: '.json_encode([
            'post_id' => $post->id,
            'analysis_status' => $persisted->status->value,
            'concept' => $persisted->concept,
            'hook' => $persisted->hook,
            'winner' => $insight?->only(['score', 'why', 'how_to_copy']),
            'winner_optional' => $insight === null
                ? 'No WinnerInsight (thresholds / metrics) - analysis still passed'
                : 'WinnerInsight persisted',
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function defaultUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => "https://instagram.com/{$handle}",
            Platform::TikTok => "https://tiktok.com/@{$handle}",
            Platform::Facebook => "https://facebook.com/{$handle}",
            Platform::LinkedIn => "https://linkedin.com/company/{$handle}",
            Platform::Youtube => "https://youtube.com/@{$handle}",
        };
    }
}
