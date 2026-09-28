<?php

namespace App\Services\Winners;

use App\Enums\AnalysisStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Models\WinnerRule;
use App\Services\Analysis\NanoGptClient;
use App\Services\Dashboard\DashboardMath;
use App\Services\SnitchAnalyticsService;
use Illuminate\Support\Collection;

class WinnerScorer
{
    public function __construct(
        private NanoGptClient $client,
        private SnitchAnalyticsService $analytics,
        private DashboardMath $math,
    ) {}

    public function ruleFor(User $user): WinnerRule
    {
        $preset = config('snitch.winners.presets.balanced');

        $rule = WinnerRule::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'preset' => 'balanced',
                ...$preset,
                'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 0],
            ],
        );

        $user->setRelation('winnerRule', $rule);

        return $rule;
    }

    /**
     * @param  Collection<int, Post>  $accountPostsNewestFirst
     * @return array{passes: bool, score: float, multiplier: float|null, reasons: list<string>}
     */
    public function evaluate(Post $post, WinnerRule $rule, ?Collection $accountPostsNewestFirst = null): array
    {
        $reasons = [];
        $accountPosts = $accountPostsNewestFirst ?? collect([$post]);
        $piMeta = $this->math->performanceIndex($post, $accountPosts);
        $multiplier = $piMeta['pi'];

        $minMultiplier = (float) ($rule->min_multiplier ?? DashboardMath::WINNER_THRESHOLD);

        if ($multiplier === null) {
            $reasons[] = 'not enough prior posts to measure usual performance';
        } elseif ($multiplier < $minMultiplier) {
            $reasons[] = 'below '.$minMultiplier.'× usual';
        }

        if ($post->posted_at !== null && $post->posted_at->lt(now()->subDays((int) $rule->recency_days))) {
            $reasons[] = 'post older than recency window';
        }

        $analysis = $post->analysis;
        $advanced = is_array($rule->advanced) ? $rule->advanced : [];

        if (($advanced['require_hook'] ?? false) && ($analysis === null || blank($analysis->hook))) {
            $reasons[] = 'missing hook analysis';
        }

        if (($advanced['require_sfx'] ?? false) && ($analysis === null || blank($analysis->sfx))) {
            $reasons[] = 'missing sfx analysis';
        }

        $score = $multiplier !== null ? round($multiplier * 10, 2) : 0.0;
        $minScore = (float) ($advanced['min_score'] ?? 0);

        if ($minScore > 0 && $score < $minScore) {
            $reasons[] = 'score below minimum';
        }

        return [
            'passes' => $reasons === [],
            'score' => $score,
            'multiplier' => $multiplier !== null ? round($multiplier, 2) : null,
            'reasons' => $reasons,
        ];
    }

    public function scoreAndPersist(
        Post $post,
        User $user,
        ?WinnerRule $rule = null,
        ?WinnerInsight $existing = null,
        ?Collection $accountPostsNewestFirst = null,
    ): ?WinnerInsight {
        $rule ??= $this->ruleFor($user);
        $verdict = $this->evaluate($post, $rule, $accountPostsNewestFirst);

        if (! $verdict['passes']) {
            WinnerInsight::query()
                ->where('user_id', $user->id)
                ->where('post_id', $post->id)
                ->delete();

            return null;
        }

        $existing ??= WinnerInsight::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->first();

        $insight = WinnerInsight::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'post_id' => $post->id,
            ],
            [
                'score' => $verdict['score'],
                'performance_multiplier' => $verdict['multiplier'],
                'why' => $this->buildWhy($post, $verdict['score'], $verdict['multiplier']),
                'how_to_copy' => $this->resolveHowToCopy($post, $existing),
            ],
        );

        if ($insight->wasRecentlyCreated) {
            $this->analytics->recordWinnerScored();
        }

        return $insight;
    }

    /**
     * @return Collection<int, WinnerInsight>
     */
    public function rescoreUser(User $user): Collection
    {
        $rule = $this->ruleFor($user);
        $insights = collect();

        $existingByPostId = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('post_id');

        $posts = Post::query()
            ->forUser($user)
            ->with('analysis')
            ->orderByDesc('posted_at')
            ->limit(200)
            ->get();

        $postsBySocial = $posts->groupBy(fn (Post $post): int => (int) $post->social_account_id);

        $posts->each(function (Post $post) use ($user, $rule, $insights, $existingByPostId, $postsBySocial): void {
            $accountPosts = $postsBySocial->get((int) $post->social_account_id, collect());
            $existing = $existingByPostId->get($post->id);
            $insight = $this->scoreAndPersist(
                $post,
                $user,
                $rule,
                $existing instanceof WinnerInsight ? $existing : null,
                $accountPosts,
            );

            if ($insight !== null) {
                $insights->push($insight);
            }
        });

        return $insights
            ->sortByDesc(fn (WinnerInsight $insight): float => (float) ($insight->performance_multiplier ?? $insight->score))
            ->values();
    }

    private function buildWhy(Post $post, float $score, ?float $multiplier): string
    {
        $metrics = is_array($post->metrics) ? $post->metrics : [];
        $views = (int) ($metrics['views'] ?? 0);
        $likes = (int) ($metrics['likes'] ?? 0);
        $hook = $post->analysis?->hook;

        $parts = [];

        if ($multiplier !== null) {
            $parts[] = sprintf('%.1f× this account\'s usual.', $multiplier);
        } else {
            $parts[] = "Score {$score}.";
        }

        if ($views > 0 || $likes > 0) {
            $parts[] = "{$views} views and {$likes} likes.";
        }

        if (filled($hook)) {
            $parts[] = 'Hook: '.$hook;
        }

        return implode(' ', $parts);
    }

    private function resolveHowToCopy(Post $post, ?WinnerInsight $existing): string
    {
        if ($this->usableCopy($existing?->how_to_copy)) {
            return trim((string) $existing->how_to_copy);
        }

        if ($this->usableCopy($post->analysis?->how_to_copy)) {
            return trim((string) $post->analysis->how_to_copy);
        }

        if ($post->analysis?->status === AnalysisStatus::Completed) {
            return $this->buildHowToCopy($post);
        }

        return 'Study the hook, pacing, and CTA, then remake with your brand voice.';
    }

    private function usableCopy(?string $copy): bool
    {
        return filled($copy) && strlen(trim($copy)) >= 20;
    }

    private function buildHowToCopy(Post $post): string
    {
        $analysis = $post->analysis;

        if ($analysis === null) {
            return 'Remake the structure with your product in the first three seconds.';
        }

        try {
            $model = (string) config('snitch.winners.copy_model');
            $response = $this->client->chat(
                messages: [
                    [
                        'role' => 'user',
                        'content' => "Write 2-4 short remake steps for this post.\nHook: {$analysis->hook}\nIdea: {$analysis->idea}\nVisual: {$analysis->visual_summary}\nCTA: {$analysis->cta}",
                    ],
                ],
                model: $model,
                options: [
                    'temperature' => 0.4,
                    'max_tokens' => 400,
                ],
            );

            $text = $this->client->extractAssistantText($response);

            if ($this->usableCopy($text)) {
                return trim($text);
            }
        } catch (\Throwable) {
            // Fall through to deterministic copy.
        }

        return trim("1) Open with: {$analysis->hook}\n2) Visual plan: {$analysis->visual_summary}\n3) Deliver idea: {$analysis->idea}\n4) Close with: {$analysis->cta}");
    }
}
