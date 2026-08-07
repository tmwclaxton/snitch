<?php

namespace App\Services\Winners;

use App\Enums\AnalysisStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Models\WinnerRule;
use App\Services\Analysis\NanoGptClient;
use Illuminate\Support\Collection;

class WinnerScorer
{
    public function __construct(private NanoGptClient $client) {}

    public function ruleFor(User $user): WinnerRule
    {
        $preset = config('snitch.winners.presets.balanced');

        $rule = WinnerRule::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'preset' => 'balanced',
                ...$preset,
                'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 40],
            ],
        );

        $user->setRelation('winnerRule', $rule);

        return $rule;
    }

    /**
     * @return array{passes: bool, score: float, reasons: list<string>}
     */
    public function evaluate(Post $post, WinnerRule $rule): array
    {
        $metrics = is_array($post->metrics) ? $post->metrics : [];
        $views = (int) ($metrics['views'] ?? 0);
        $likes = (int) ($metrics['likes'] ?? 0);
        $comments = (int) ($metrics['comments'] ?? 0);
        $shares = (int) ($metrics['shares'] ?? 0);
        $reasons = [];

        if ($views < (int) $rule->min_views) {
            $reasons[] = 'views below threshold';
        }

        if ($likes < (int) $rule->min_likes) {
            $reasons[] = 'likes below threshold';
        }

        $engagementRate = $views > 0 ? (($likes + $comments + $shares) / $views) * 100 : 0;

        if ($engagementRate < (int) $rule->min_engagement_rate) {
            $reasons[] = 'engagement rate below threshold';
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

        $weights = is_array($rule->weights) ? $rule->weights : [];
        $score = $this->scoreMetrics($views, $likes, $comments, $shares, $weights);

        $minScore = (float) ($advanced['min_score'] ?? 0);

        if ($score < $minScore) {
            $reasons[] = 'score below minimum';
        }

        return [
            'passes' => $reasons === [],
            'score' => round($score, 2),
            'reasons' => $reasons,
        ];
    }

    public function scoreAndPersist(Post $post, ?WinnerRule $rule = null): ?WinnerInsight
    {
        $rule ??= $this->ruleFor($post->user);
        $verdict = $this->evaluate($post, $rule);

        if (! $verdict['passes']) {
            WinnerInsight::query()
                ->where('user_id', $post->user_id)
                ->where('post_id', $post->id)
                ->delete();

            return null;
        }

        $why = $this->buildWhy($post, $verdict['score']);
        $howToCopy = $post->analysis?->status === AnalysisStatus::Completed
            ? $this->buildHowToCopy($post)
            : 'Study the hook, pacing, and CTA, then remake with your brand voice.';

        return WinnerInsight::query()->updateOrCreate(
            [
                'user_id' => $post->user_id,
                'post_id' => $post->id,
            ],
            [
                'score' => $verdict['score'],
                'why' => $why,
                'how_to_copy' => $howToCopy,
            ],
        );
    }

    /**
     * @return Collection<int, WinnerInsight>
     */
    public function rescoreUser(User $user): Collection
    {
        $rule = $this->ruleFor($user);
        $insights = collect();

        $user->loadMissing(['trackedAccounts']);

        Post::query()
            ->where('user_id', $user->id)
            ->with('analysis')
            ->orderByDesc('posted_at')
            ->limit(100)
            ->get()
            ->each(function (Post $post) use ($rule, $insights): void {
                $insight = $this->scoreAndPersist($post, $rule);

                if ($insight !== null) {
                    $insights->push($insight);
                }
            });

        return $insights->sortByDesc('score')->values();
    }

    /**
     * @param  array<string, float|int>  $weights
     */
    private function scoreMetrics(int $views, int $likes, int $comments, int $shares, array $weights): float
    {
        $wViews = (float) ($weights['views'] ?? 0.4);
        $wLikes = (float) ($weights['likes'] ?? 0.3);
        $wComments = (float) ($weights['comments'] ?? 0.2);
        $wShares = (float) ($weights['shares'] ?? 0.1);

        $normalizedViews = min(100, log10(max(1, $views)) * 20);
        $normalizedLikes = min(100, log10(max(1, $likes)) * 25);
        $normalizedComments = min(100, log10(max(1, $comments + 1)) * 30);
        $normalizedShares = min(100, log10(max(1, $shares + 1)) * 35);

        return ($normalizedViews * $wViews)
            + ($normalizedLikes * $wLikes)
            + ($normalizedComments * $wComments)
            + ($normalizedShares * $wShares);
    }

    private function buildWhy(Post $post, float $score): string
    {
        $metrics = is_array($post->metrics) ? $post->metrics : [];
        $views = (int) ($metrics['views'] ?? 0);
        $likes = (int) ($metrics['likes'] ?? 0);
        $hook = $post->analysis?->hook;

        $parts = [
            "Score {$score}.",
            "{$views} views and {$likes} likes cleared your thresholds.",
        ];

        if (filled($hook)) {
            $parts[] = 'Hook: '.$hook;
        }

        return implode(' ', $parts);
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

            if (strlen($text) >= 20) {
                return $text;
            }
        } catch (\Throwable) {
            // Fall through to deterministic copy.
        }

        return trim("1) Open with: {$analysis->hook}\n2) Visual plan: {$analysis->visual_summary}\n3) Deliver idea: {$analysis->idea}\n4) Close with: {$analysis->cta}");
    }
}
