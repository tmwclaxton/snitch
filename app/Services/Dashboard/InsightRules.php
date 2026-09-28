<?php

namespace App\Services\Dashboard;

/**
 * Deterministic, templated insight sentences for the dashboard.
 *
 * @phpstan-type InsightCandidate array{
 *     category: string,
 *     text: string,
 *     score: float,
 *     n: int,
 *     links_to: string,
 *     detail?: string|null,
 *     post_id?: int|null
 * }
 */
class InsightRules
{
    /**
     * Legacy / semantic keys mapped onto ids that exist on Dashboard.vue.
     *
     * @var array<string, string>
     */
    public const ANCHOR_ALIASES = [
        'kpis' => 'rail',
    ];

    /**
     * Every value here must appear as `id="…"` or `anchor="…"` on Dashboard.vue.
     * Path-style targets (`/winners`, `/tracking/1`) skip this list and navigate.
     *
     * @var list<string>
     */
    public const LIVE_ANCHORS = [
        'onboarding',
        'rail',
        'insights',
        'leaderboard',
        'winners',
        'growth_series',
        'activity',
        'efficiency',
        'format_mix',
        'format_lift',
        'heatmap',
        'captions',
        'caption_intel',
        'recent_posts',
        'themes',
        'weekly',
        'attention',
        'actions',
        'data_notes',
    ];

    public function resolveAnchor(string $linksTo): string
    {
        if (str_starts_with($linksTo, '/')) {
            return $linksTo;
        }

        $target = self::ANCHOR_ALIASES[$linksTo] ?? $linksTo;

        return in_array($target, self::LIVE_ANCHORS, true) ? $target : 'insights';
    }

    /**
     * @param  array<string, mixed>  $context  Precomputed metrics from DashboardMetrics
     * @return list<InsightCandidate>
     */
    public function candidates(array $context): array
    {
        $out = [];

        foreach ($this->formatGap($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->formatLift($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->winnerSpotlight($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->timing($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->frequency($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->efficiency($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->growth($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->captionCta($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->hashtags($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->themeGap($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->wowChange($context) as $candidate) {
            $out[] = $candidate;
        }

        foreach ($this->yourWin($context) as $candidate) {
            $out[] = $candidate;
        }

        return $out;
    }

    /**
     * Top insights: max 5, at most 1 per category, n≥5 and effect threshold already applied.
     *
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    public function top(array $context, int $limit = 5): array
    {
        $ranked = collect($this->candidates($context))
            ->filter(fn (array $row): bool => $row['n'] >= DashboardMath::MIN_SAMPLE && $row['score'] > 0)
            ->sortByDesc('score')
            ->values();

        $picked = [];
        $seen = [];

        foreach ($ranked as $row) {
            if (isset($seen[$row['category']])) {
                continue;
            }

            $seen[$row['category']] = true;
            $row['links_to'] = $this->resolveAnchor((string) $row['links_to']);
            $row['detail'] = $row['detail'] ?? sprintf('Based on n=%d · effect score %.2f', $row['n'], $row['score']);
            $row['post_id'] = isset($row['post_id']) ? (int) $row['post_id'] : null;
            $picked[] = $row;

            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function formatGap(array $context): array
    {
        $own = $context['own'] ?? null;
        $rivals = $context['rival_rows'] ?? [];

        if (! is_array($own) || ($own['posts_n'] ?? 0) < DashboardMath::MIN_SAMPLE) {
            return [];
        }

        $out = [];

        foreach ($rivals as $rival) {
            if (! is_array($rival) || ($rival['posts_n'] ?? 0) < DashboardMath::MIN_SAMPLE) {
                continue;
            }

            foreach (['Reel', 'Carousel'] as $format) {
                $ownShare = (float) ($own['format_share'][$format] ?? 0);
                $rivalShare = (float) ($rival['format_share'][$format] ?? 0);
                $ownEr = (float) ($own['er'] ?? 0);
                $rivalEr = (float) ($rival['er'] ?? 0);

                if ($ownShare <= 0 || $rivalShare < 1.5 * $ownShare) {
                    continue;
                }

                if ($ownEr <= 0 || $rivalEr < 1.5 * $ownEr) {
                    continue;
                }

                $shareRatio = $rivalShare / max($ownShare, 0.01);
                $erRatio = $rivalEr / max($ownEr, 0.01);
                $n = min((int) $own['posts_n'], (int) $rival['posts_n']);
                $effect = min($shareRatio, $erRatio);

                $out[] = [
                    'category' => 'format_gap',
                    'text' => sprintf(
                        '**@%s** posts **%s× more %ss** than you (%s%% vs %s%% of posts) and gets **%s× your engagement per follower**.',
                        $rival['handle'],
                        $this->x($shareRatio),
                        $format,
                        $this->pct($rivalShare),
                        $this->pct($ownShare),
                        $this->x($erRatio),
                    ),
                    'score' => $effect * min(1, $n / 20),
                    'n' => $n,
                    'links_to' => 'format_mix',
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function formatLift(array $context): array
    {
        $lifts = $context['peer_format_lift'] ?? [];
        $out = [];

        foreach ($lifts as $format => $row) {
            if (! is_array($row)) {
                continue;
            }

            $lift = (float) ($row['lift'] ?? 0);
            $n = (int) ($row['n'] ?? 0);

            if ($n < 8 || $lift < 1.3) {
                continue;
            }

            $parts = [];

            foreach ($lifts as $f => $r) {
                if (! is_array($r) || ($r['n'] ?? 0) < 3) {
                    continue;
                }

                $parts[] = sprintf('%ss do %s×', strtolower((string) $f), $this->x((float) $r['lift']));
            }

            if ($parts === []) {
                continue;
            }

            $out[] = [
                'category' => 'format_lift',
                'text' => 'Across your competitors, **'.implode('. ', [
                    ucfirst($parts[0] ?? ''),
                    ...array_slice($parts, 1),
                ]).'**.',
                'score' => ($lift - 1) * min(1, $n / 20),
                'n' => $n,
                'links_to' => 'format_lift',
            ];

            break;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function winnerSpotlight(array $context): array
    {
        $winner = $context['top_winner'] ?? null;

        if (! is_array($winner) || ($winner['pi'] ?? 0) < 2.5) {
            return [];
        }

        $n = (int) ($winner['prior_n'] ?? 0);

        if ($n < DashboardMath::MIN_SAMPLE) {
            return [];
        }

        return [[
            'category' => 'winner_spotlight',
            'text' => sprintf(
                '**@%s**\'s best post this month got **%s× their usual engagement**: a %s%s, posted %s.',
                $winner['handle'],
                $this->x((float) $winner['pi']),
                strtolower((string) ($winner['format'] ?? 'post')),
                filled($winner['hook'] ?? null) ? ' - "'.$this->plain((string) $winner['hook']).'"' : '',
                (string) ($winner['when'] ?? 'recently'),
            ),
            'score' => ((float) $winner['pi'] - 1) * min(1, $n / 20),
            'n' => $n,
            'links_to' => 'winners',
            'detail' => sprintf(
                'PI %s× · prior sample n=%d · format %s',
                $this->x((float) $winner['pi']),
                $n,
                (string) ($winner['format'] ?? 'post'),
            ),
            'post_id' => isset($winner['id']) ? (int) $winner['id'] : null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function timing(array $context): array
    {
        $cell = $context['best_heatmap_cell'] ?? null;

        if (! is_array($cell)) {
            return [];
        }

        $pi = (float) ($cell['pi'] ?? 0);
        $n = (int) ($cell['n'] ?? 0);

        if ($n < DashboardMath::MIN_SAMPLE || $pi < 1.4) {
            return [];
        }

        return [[
            'category' => 'timing',
            'text' => sprintf(
                'Competitor posts published **%s %s** tend to do **%s× better** than average (%d posts).',
                (string) $cell['day'],
                (string) $cell['block'],
                $this->x($pi),
                $n,
            ),
            'score' => ($pi - 1) * min(1, $n / 20),
            'n' => $n,
            'links_to' => 'heatmap',
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function frequency(array $context): array
    {
        $own = $context['own'] ?? null;
        $peerPostsWk = $context['peer_posts_per_week'] ?? null;

        if (! is_array($own) || ! is_numeric($peerPostsWk) || (float) $peerPostsWk <= 0) {
            return [];
        }

        $ownWk = (float) ($own['posts_per_week'] ?? 0);
        $n = (int) ($own['posts_n'] ?? 0) + (int) ($context['rival_posts_n'] ?? 0);

        if ($n < DashboardMath::MIN_SAMPLE || $ownWk > 0.5 * (float) $peerPostsWk) {
            return [];
        }

        $weeks = 4;
        $ownCount = (int) round($ownWk * $weeks);

        return [[
            'category' => 'frequency',
            'text' => sprintf(
                'You posted **%s in the last 4 weeks**; your competitors post a median **%s×/week**.',
                $ownCount === 1 ? 'once' : "{$ownCount} times",
                $this->x((float) $peerPostsWk),
            ),
            'score' => (((float) $peerPostsWk / max($ownWk, 0.1)) - 1) * min(1, $n / 20),
            'n' => $n,
            'links_to' => 'rail',
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function efficiency(array $context): array
    {
        $peerPostsWk = (float) ($context['peer_posts_per_week'] ?? 0);
        $peerEr = (float) ($context['peer_er'] ?? 0);
        $rivals = $context['rival_rows'] ?? [];

        if ($peerPostsWk <= 0 || $peerEr <= 0) {
            return [];
        }

        foreach ($rivals as $rival) {
            if (! is_array($rival) || ($rival['posts_n'] ?? 0) < DashboardMath::MIN_SAMPLE) {
                continue;
            }

            $postsWk = (float) ($rival['posts_per_week'] ?? 0);
            $er = (float) ($rival['er'] ?? 0);

            if ($postsWk <= $peerPostsWk && $er >= 2 * $peerEr) {
                $n = (int) $rival['posts_n'];

                return [[
                    'category' => 'efficiency',
                    'text' => sprintf(
                        '**@%s** posts less than anyone in your set but gets the **highest engagement**: quality over volume.',
                        $rival['handle'],
                    ),
                    'score' => ($er / max($peerEr, 0.01)) * min(1, $n / 20),
                    'n' => $n,
                    'links_to' => 'efficiency',
                ]];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function growth(array $context): array
    {
        $peerGrowth = $context['peer_growth_pct'] ?? null;
        $rivals = $context['rival_rows'] ?? [];

        if (! is_numeric($peerGrowth)) {
            return [];
        }

        foreach ($rivals as $rival) {
            if (! is_array($rival)) {
                continue;
            }

            $growth = $rival['growth_pct'] ?? null;

            if (! is_numeric($growth) || (float) $growth < 3) {
                continue;
            }

            if ((float) $growth < 2 * (float) $peerGrowth && (float) $peerGrowth > 0) {
                continue;
            }

            $n = max(DashboardMath::MIN_SAMPLE, (int) ($rival['posts_n'] ?? 0));
            $winners = (int) ($rival['winners'] ?? 0);
            $extra = $winners > 0
                ? sprintf(' %d of their winners were standout posts.', $winners)
                : '';

            return [[
                'category' => 'growth',
                'text' => sprintf(
                    '**@%s** grew **%s%%** this month, fastest in your set.%s',
                    $rival['handle'],
                    $this->pct((float) $growth),
                    $extra,
                ),
                'score' => ((float) $growth / 10) * min(1, $n / 20),
                'n' => $n,
                'links_to' => 'growth_series',
            ]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function captionCta(array $context): array
    {
        $cta = $context['cta_question'] ?? null;

        if (! is_array($cta)) {
            return [];
        }

        $ratio = (float) ($cta['comment_ratio'] ?? 0);
        $n = (int) ($cta['n'] ?? 0);

        if ($n < 10 || $ratio < 1.3) {
            return [];
        }

        return [[
            'category' => 'caption_cta',
            'text' => sprintf(
                'Posts that **ask a question** get **%s× more comments** across your set.',
                $this->x($ratio),
            ),
            'score' => ($ratio - 1) * min(1, $n / 20),
            'n' => $n,
            'links_to' => 'captions',
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function hashtags(array $context): array
    {
        $row = $context['hashtag_lift'] ?? null;

        if (! is_array($row)) {
            return [];
        }

        $ratio = (float) ($row['ratio'] ?? 0);
        $n = (int) ($row['n'] ?? 0);

        if ($n < DashboardMath::MIN_SAMPLE || $ratio < 1.2) {
            return [];
        }

        return [[
            'category' => 'hashtags',
            'text' => sprintf(
                'Posts with **3 or fewer hashtags** outperform hashtag-heavy posts (%s×).',
                $this->x($ratio),
            ),
            'score' => ($ratio - 1) * min(1, $n / 20),
            'n' => $n,
            'links_to' => 'captions',
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function themeGap(array $context): array
    {
        $gap = $context['theme_gap'] ?? null;

        if (! is_array($gap) || ($gap['n'] ?? 0) < DashboardMath::MIN_SAMPLE) {
            return [];
        }

        $pi = (float) ($gap['pi'] ?? 0);

        if ($pi < 1.3) {
            return [];
        }

        return [[
            'category' => 'theme_gap',
            'text' => sprintf(
                'Peers\' **%s** posts do %s× their usual. You haven\'t posted one yet.',
                (string) $gap['theme'],
                $this->x($pi),
            ),
            'score' => ($pi - 1) * min(1, ((int) $gap['n']) / 20),
            'n' => (int) $gap['n'],
            'links_to' => 'themes',
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function wowChange(array $context): array
    {
        $change = $context['wow_change'] ?? null;

        if (! is_array($change) || ($change['n'] ?? 0) < DashboardMath::MIN_SAMPLE) {
            return [];
        }

        $pct = abs((float) ($change['pct'] ?? 0));

        if ($pct < 50) {
            return [];
        }

        return [[
            'category' => 'wow_change',
            'text' => sprintf(
                '**@%s** %s its posting this week (%s → %s) and its engagement %s.',
                $change['handle'],
                ((float) $change['pct'] >= 0 ? 'increased' : 'cut'),
                (string) $change['from'],
                (string) $change['to'],
                (string) ($change['er_note'] ?? 'held steady'),
            ),
            'score' => ($pct / 100) * min(1, ((int) $change['n']) / 20),
            'n' => (int) $change['n'],
            'links_to' => 'weekly',
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<InsightCandidate>
     */
    private function yourWin(array $context): array
    {
        $win = $context['your_win'] ?? null;

        if (! is_array($win) || ($win['pi'] ?? 0) < DashboardMath::WINNER_THRESHOLD) {
            return [];
        }

        $n = (int) ($win['prior_n'] ?? DashboardMath::MIN_SAMPLE);

        return [[
            'category' => 'your_win',
            'text' => sprintf(
                'Your **%s** did **%s× your usual**. More like this?',
                filled($win['hook'] ?? null)
                    ? $this->plain((string) $win['hook'])
                    : strtolower((string) ($win['format'] ?? 'post')),
                $this->x((float) $win['pi']),
            ),
            'score' => ((float) $win['pi']) * min(1, $n / 20),
            'n' => max($n, DashboardMath::MIN_SAMPLE),
            'links_to' => 'winners',
            'detail' => sprintf('PI %s× · prior sample n=%d', $this->x((float) $win['pi']), $n),
            'post_id' => isset($win['id']) ? (int) $win['id'] : null,
        ]];
    }

    private function x(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') ?: '0';
    }

    private function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') ?: '0';
    }

    private function plain(string $text): string
    {
        $clean = preg_replace('/[*_`]/', '', $text) ?? $text;

        return mb_strlen($clean) > 80 ? rtrim(mb_substr($clean, 0, 79)).'…' : $clean;
    }
}
