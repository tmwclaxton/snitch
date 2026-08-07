<?php

namespace App\Console\Commands;

use App\DataTransferObjects\VideoAnalysisResult;
use App\Enums\Platform;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\VideoAnalysisService;
use App\Services\Analysis\VideoAnalysisSuccessEvaluator;
use App\Services\Winners\WinnerScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:probe-e2e {--user=} {--handle=} {--platform=instagram}')]
#[Description('End-to-end Snitch probe: brand, sync, analyze, winners')]
class ProbeE2eCommand extends Command
{
    public function handle(
        VideoAnalysisService $analysis,
        VideoAnalysisSuccessEvaluator $evaluator,
        WinnerScorer $scorer,
    ): int {
        if (! filter_var((string) env('SNITCH_LIVE_E2E', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('SNITCH_LIVE_E2E is not enabled. Skipping live e2e probe.');

            return self::SUCCESS;
        }

        $email = (string) $this->option('user');
        $handle = ltrim((string) $this->option('handle'), '@');
        $platform = Platform::from((string) $this->option('platform'));

        if ($email === '' || $handle === '') {
            $this->error('--user and --handle are required for live e2e.');

            return self::FAILURE;
        }

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
                'url' => "https://{$platform->value}.com/{$handle}",
                'display_name' => $handle,
            ],
        );

        SyncTrackedAccountJob::dispatchSync($account->id);

        $post = Post::query()
            ->where('tracked_account_id', $account->id)
            ->whereNotNull('media_url')
            ->latest('posted_at')
            ->first();

        if ($post === null) {
            $this->error('No normalized posts after sync.');

            return self::FAILURE;
        }

        $this->info("Synced post {$post->id}");

        if (in_array($post->type->value, ['reel', 'video'], true) && filled($post->media_url)) {
            $result = $analysis->analyzeUrl((string) $post->media_url, 'video', $post->caption);
            $evaluation = $evaluator->evaluate($result);

            if (! $evaluation['passed']) {
                $this->error('Analysis checklist failed: '.implode(', ', $evaluation['failures']));

                return self::FAILURE;
            }
        } else {
            $result = VideoAnalysisResult::fromModelPayload([
                'hook' => 'Probe synthetic hook line',
                'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                'visual_summary' => str_repeat('Visual summary for non-video probe. ', 3),
                'idea' => 'Synthetic idea line',
                'cta' => 'Follow for more',
                'how_to_copy' => 'Remake with your brand product in frame one.',
                'sfx' => [],
            ], 'synthetic');
        }

        $insight = $scorer->scoreAndPersist($post->fresh('analysis'));
        $this->info('Winner report: '.json_encode([
            'post_id' => $post->id,
            'analysis_model' => $result->model,
            'winner' => $insight?->only(['score', 'why', 'how_to_copy']),
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
