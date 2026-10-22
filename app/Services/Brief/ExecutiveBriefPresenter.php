<?php

namespace App\Services\Brief;

use App\Mcp\Support\McpAppUrls;
use App\Models\DailyBrief;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\DashboardMath;
use App\Support\DailyBriefPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ExecutiveBriefPresenter
{
    public function __construct(
        private DailyBriefPresenter $dailyPresenter,
        private DailyBriefGenerator $generator,
    ) {}

    /**
     * Memo shape for the Executive Brief page and get_executive_brief.
     *
     * @return array{
     *     empty: bool,
     *     empty_reason: string|null,
     *     brief_date: string|null,
     *     generated_at: string|null,
     *     headline: string|null,
     *     summary: string|null,
     *     own_activity: list<array{text: string, posts: list<array<string, mixed>>}>,
     *     competitor_activity: list<array{text: string, handle: string|null, posts: list<array<string, mixed>>}>,
     *     suggestions: list<array{number: int, title: string, why: string, evidence: list<array<string, mixed>>}>,
     *     freshness: array{line: string|null, failed_handles: list<string>},
     *     chat_url: string,
     *     competitors_url: string,
     *     curator_overrides: array{summary: bool, suggestions: bool, updated_at: string|null},
     *     insufficient_data: bool
     * }
     */
    public function forUser(User $user, ?CarbonImmutable $date = null): array
    {
        $date ??= $this->generator->briefDate();
        $hasTrackers = TrackedAccount::query()->where('user_id', $user->id)->exists();

        if (! $hasTrackers) {
            return $this->emptyState(
                'Add competitors so Snitch can write your Executive Brief from real posts.',
            );
        }

        $brief = $this->generator->briefForDate($user, $date);

        if ($brief === null) {
            $latest = DailyBrief::query()
                ->where('user_id', $user->id)
                ->where('status', 'ready')
                ->orderByDesc('brief_date')
                ->first();

            if ($latest !== null) {
                $brief = $latest;
            }
        }

        if ($brief === null || $brief->status !== 'ready') {
            return $this->emptyState(
                'Your first Executive Brief arrives after the morning refresh once there is enough tracked data.',
            );
        }

        return $this->fromBrief($brief);
    }

    /**
     * @return array<string, mixed>
     */
    public function fromBrief(DailyBrief $brief): array
    {
        $payload = $this->dailyPresenter->payload($brief);
        $facts = is_array($brief->facts) ? $brief->facts : [];
        $overrides = is_array($payload['curator_overrides'] ?? null)
            ? $payload['curator_overrides']
            : [];

        $agentSource = $this->resolveAgentSource($brief, $payload);
        $agentPayload = $agentSource['payload'];
        $hasAgentMemo = $agentSource['has_agent'];
        $awaitingNarrative = ! $hasAgentMemo;

        $summary = $hasAgentMemo
            ? $this->resolveSummary($agentPayload, $overrides, $facts)
            : null;
        $narrative = $hasAgentMemo ? $this->agentNarrative($agentPayload) : null;
        // Agent memos: interpretive prose only for "what happened" - never the per-handle stats inventory.
        $ownActivity = $hasAgentMemo ? $this->interpretiveOwnActivity($agentPayload, $facts) : [];
        $competitorActivity = $hasAgentMemo
            ? $this->interpretiveCompetitorActivity($agentPayload, $facts, $narrative)
            : [];
        $suggestions = $this->resolveSuggestions(
            $hasAgentMemo ? $agentPayload : $payload,
            $overrides,
            $facts,
        );
        // Never clip the yellow headline into a subtitle repeat - wrap in the column instead.
        $headline = $hasAgentMemo
            ? $this->resolveHeadline($agentPayload, $facts)
            : $this->resolveHeadline($payload, $facts);
        $signals = $this->signals($facts);
        $keyFigures = $this->keyFigures($facts, $payload);
        $insufficient = $suggestions === [];

        return [
            'empty' => false,
            'empty_reason' => null,
            'brief_date' => $brief->brief_date?->toDateString(),
            'generated_at' => $brief->generated_at?->timezone(DashboardMath::TIMEZONE)->toIso8601String(),
            'headline' => $headline,
            'headline_detail' => null,
            'narrative' => $narrative,
            'awaiting_narrative' => $awaitingNarrative,
            'narrative_note' => $awaitingNarrative
                ? 'Today\'s written brief is still drafting. Actions below use stored figures until the consultant rewrite lands.'
                : null,
            'summary' => $summary,
            'summary_bullets' => $this->summaryBullets($summary),
            'own_activity' => $ownActivity,
            'competitor_activity' => $competitorActivity,
            'suggestions' => $suggestions,
            'signals' => $signals,
            'key_figures' => $keyFigures,
            'show_cadence_visual' => $hasAgentMemo
                && $this->shouldShowCadenceVisual($headline, $signals, $facts),
            'freshness' => $this->freshnessSummary($payload),
            'chat_url' => rtrim((string) config('app.url'), '/').'/chat',
            'competitors_url' => rtrim((string) config('app.url'), '/').'/competitors',
            'curator_overrides' => [
                'summary' => filled($overrides['summary'] ?? null),
                'suggestions' => is_array($overrides['suggestions'] ?? null),
                'updated_at' => is_string($overrides['updated_at'] ?? null)
                    ? $overrides['updated_at']
                    : null,
            ],
            'insufficient_data' => $insufficient,
            'app_url' => rtrim((string) config('app.url'), '/').'/executive-brief',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(string $reason): array
    {
        return [
            'empty' => true,
            'empty_reason' => $reason,
            'brief_date' => null,
            'generated_at' => null,
            'headline' => null,
            'headline_detail' => null,
            'narrative' => null,
            'awaiting_narrative' => false,
            'narrative_note' => null,
            'summary' => null,
            'summary_bullets' => [],
            'own_activity' => [],
            'competitor_activity' => [],
            'suggestions' => [],
            'signals' => $this->emptySignals(),
            'key_figures' => $this->emptyKeyFigures(),
            'show_cadence_visual' => false,
            'freshness' => [
                'line' => null,
                'failed_handles' => [],
            ],
            'chat_url' => rtrim((string) config('app.url'), '/').'/chat',
            'competitors_url' => rtrim((string) config('app.url'), '/').'/competitors',
            'curator_overrides' => [
                'summary' => false,
                'suggestions' => false,
                'updated_at' => null,
            ],
            'insufficient_data' => true,
            'app_url' => rtrim((string) config('app.url'), '/').'/executive-brief',
        ];
    }

    /**
     * Agent-written memo only. Never concatenate deterministic summary facts as "narrative".
     *
     * @param  array<string, mixed>  $payload
     * @return array{has_agent: bool, payload: array<string, mixed>}
     */
    private function resolveAgentSource(DailyBrief $brief, array $payload): array
    {
        if ($this->hasAgentNarrative($payload)) {
            return ['has_agent' => true, 'payload' => $payload];
        }

        $prior = DailyBrief::query()
            ->where('user_id', $brief->user_id)
            ->where('status', 'ready')
            ->where('id', '!=', $brief->id)
            ->orderByDesc('brief_date')
            ->limit(5)
            ->get();

        foreach ($prior as $candidate) {
            $candidatePayload = $this->dailyPresenter->payload($candidate);
            if ($this->hasAgentNarrative($candidatePayload)) {
                return ['has_agent' => true, 'payload' => $candidatePayload];
            }
        }

        return ['has_agent' => false, 'payload' => $payload];
    }

    /**
     * Intentional prose only: automation narrative, or a curator summary override.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hasAgentNarrative(array $payload): bool
    {
        return $this->agentNarrative($payload) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function agentNarrative(array $payload): ?string
    {
        $narrative = is_string($payload['narrative'] ?? null) ? trim($payload['narrative']) : '';
        $automation = $payload['automation_overrides'] ?? null;
        if ($narrative !== '' && is_array($automation) && $automation !== []) {
            return $narrative;
        }

        $curator = is_array($payload['curator_overrides'] ?? null) ? $payload['curator_overrides'] : [];
        $curatorSummary = is_string($curator['summary'] ?? null) ? trim($curator['summary']) : '';

        return $curatorSummary !== '' ? $curatorSummary : null;
    }

    /**
     * Slim margin figures: followers, days quiet, best rival post.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string, detail: string|null, href: string|null}>
     */
    private function keyFigures(array $facts, array $payload): array
    {
        $own = is_array($facts['own'] ?? null) ? $facts['own'] : [];
        $figures = [];

        $followers = $own['followers_now'] ?? null;
        if ($followers !== null && is_numeric($followers)) {
            $change = is_array($own['followers_change_7d'] ?? null)
                ? trim((string) ($own['followers_change_7d']['label'] ?? ''))
                : '';
            $figures[] = [
                'label' => 'Followers',
                'value' => number_format((int) $followers),
                'detail' => $change !== '' ? $change : null,
                'href' => null,
            ];
        }

        $daysSince = $own['days_since_last_post'] ?? null;
        if ($daysSince !== null && is_numeric($daysSince)) {
            $figures[] = [
                'label' => 'Days quiet',
                'value' => (string) (int) $daysSince,
                'detail' => null,
                'href' => null,
            ];
        }

        $bestRival = $this->bestRivalPost($facts, $payload);
        if ($bestRival !== null) {
            $figures[] = [
                'label' => (string) ($bestRival['label'] ?? 'Best rival post'),
                'value' => (string) ($bestRival['times_usual_label'] ?? $bestRival['value'] ?? ''),
                'detail' => (string) ($bestRival['detail'] ?? null),
                'href' => is_string($bestRival['href'] ?? null) ? $bestRival['href'] : null,
            ];
        }

        return $figures;
    }

    /**
     * @return list<array{label: string, value: string, detail: string|null, href: string|null}>
     */
    private function emptyKeyFigures(): array
    {
        return [];
    }

    /**
     * Prefer the best in-window rival hit; otherwise label as this month and link the post.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $payload
     * @return array{label: string, times_usual_label: string, detail: string, value: string, href: string|null}|null
     */
    private function bestRivalPost(array $facts, array $payload): ?array
    {
        $ownHandle = $this->normaliseHandle((string) ($facts['own']['handle'] ?? ''));
        $inWeek = $this->collectRivalPosts($facts, $payload, $ownHandle, true);
        $any = $inWeek !== [] ? $inWeek : $this->collectRivalPosts($facts, $payload, $ownHandle, false);
        $fromWeek = $inWeek !== [];

        $best = null;
        $bestScore = 0.0;
        foreach ($any as $post) {
            $score = (float) ($post['times_usual'] ?? 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $post;
            }
        }

        if ($best === null) {
            return null;
        }

        $enriched = $this->enrichPosts([$best]);
        $row = $enriched[0] ?? $best;
        $handle = (string) ($row['handle'] ?? $best['handle'] ?? 'rival');
        $format = (string) ($row['format'] ?? $best['format'] ?? 'post');
        $label = is_string($row['times_usual_label'] ?? null) && $row['times_usual_label'] !== ''
            ? (string) $row['times_usual_label']
            : (is_string($best['times_usual_label'] ?? null) && $best['times_usual_label'] !== ''
                ? (string) $best['times_usual_label']
                : (rtrim(rtrim(number_format($bestScore, 1), '0'), '.').'x'));
        $href = is_string($row['snitch_url'] ?? null) && $row['snitch_url'] !== ''
            ? (string) $row['snitch_url']
            : (is_string($row['url'] ?? null) && $row['url'] !== '' ? (string) $row['url'] : null);

        return [
            'label' => $fromWeek ? 'Best rival post this week' : 'Best rival post this month',
            'times_usual_label' => $label,
            'value' => $label,
            'detail' => '@'.$handle.' '.$format,
            'href' => $href,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function collectRivalPosts(array $facts, array $payload, string $ownHandle, bool $weekOnly): array
    {
        $candidates = [];
        $walker = function (mixed $value) use (&$candidates, &$walker, $ownHandle, $weekOnly): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['post_id'], $value['times_usual']) && is_numeric($value['times_usual'])) {
                $handle = $this->normaliseHandle((string) ($value['handle'] ?? ''));
                if ($ownHandle !== '' && $handle === $ownHandle) {
                    // skip own
                } elseif ($weekOnly && ! $this->postLooksInWeekWindow($value)) {
                    // skip older standouts when asking for this week
                } else {
                    $candidates[] = $value;
                }
            }
            foreach ($value as $child) {
                $walker($child);
            }
        };

        if ($weekOnly) {
            $walker($facts['top_competitor_hit_7d'] ?? null);
            foreach (is_array($facts['competitors'] ?? null) ? $facts['competitors'] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $walker($row['best_post_7d'] ?? null);
                $walker($row['posts_last_7d'] ?? null);
            }
            foreach (is_array($payload['competitor_moves'] ?? null) ? $payload['competitor_moves'] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $walker($row['posts_last_7d'] ?? null);
                $walker($row['best_post_7d'] ?? null);
            }
        } else {
            $walker($facts['top_competitor_hit_7d'] ?? null);
            $walker($facts['competitors'] ?? null);
            $walker($payload['competitor_moves'] ?? null);
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function postLooksInWeekWindow(array $post): bool
    {
        if (isset($post['in_last_7d'])) {
            return (bool) $post['in_last_7d'];
        }

        $postedAt = is_string($post['posted_at'] ?? null) ? $post['posted_at'] : null;
        if ($postedAt !== null && $postedAt !== '') {
            try {
                $at = CarbonImmutable::parse($postedAt)->timezone(DashboardMath::TIMEZONE);

                return $at->greaterThanOrEqualTo(
                    CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfDay()->subDays(7),
                );
            } catch (\Throwable) {
                // fall through
            }
        }

        // Keys from 7d bags are treated as in-window; standout_winners alone are not.
        return false;
    }

    /**
     * Only show the cadence strip when it proves a quiet / cadence headline.
     *
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $facts
     */
    private function shouldShowCadenceVisual(?string $headline, array $signals, array $facts): bool
    {
        if ($headline === null || trim($headline) === '') {
            return false;
        }

        $days = $signals['cadence_days'] ?? [];
        if (! is_array($days) || $days === []) {
            return false;
        }

        $ownPosts = $signals['own_posts_7d'] ?? null;
        $rivalMedian = $signals['rival_median_posts_7d'] ?? null;
        $lower = mb_strtolower($headline);
        $provesQuiet = str_contains($lower, 'quiet')
            || str_contains($lower, 'days')
            || str_contains($lower, 'cadence')
            || str_contains($lower, 'posted')
            || str_contains($lower, 'silence');

        if (! $provesQuiet) {
            return false;
        }

        if ($ownPosts === null) {
            return false;
        }

        // Prefer when own cadence is behind rivals, or the week is empty.
        if ((int) $ownPosts === 0) {
            return true;
        }

        if ($rivalMedian !== null && (int) $ownPosts < (int) $rivalMedian) {
            return true;
        }

        $own = is_array($facts['own'] ?? null) ? $facts['own'] : [];
        $daysSince = isset($own['days_since_last_post']) ? (int) $own['days_since_last_post'] : null;

        return $daysSince !== null && $daysSince >= 3;
    }

    /**
     * Keep the yellow headline short (~90 chars); put the rest under it.
     *
     * @return array{headline: string|null, detail: string|null}
     */
    private function splitHeadline(?string $headline): array
    {
        $headline = is_string($headline) ? trim($headline) : '';
        if ($headline === '') {
            return ['headline' => null, 'detail' => null];
        }

        if (mb_strlen($headline) <= 90) {
            return ['headline' => $headline, 'detail' => null];
        }

        if (preg_match('/^(.+?[.!?])\s+(.+)$/u', $headline, $match) === 1) {
            $first = trim($match[1]);
            $rest = trim($match[2]);
            if (mb_strlen($first) <= 90 && $rest !== '') {
                return ['headline' => $first, 'detail' => $rest];
            }
        }

        $cut = mb_substr($headline, 0, 87);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 40) {
            $cut = mb_substr($cut, 0, $space);
        }

        return [
            'headline' => rtrim($cut, '.,;:').'.',
            'detail' => $headline,
        ];
    }

    /**
     * At most 3 interpretive bullets for the memo summary.
     *
     * @return list<string>
     */
    private function summaryBullets(?string $summary): array
    {
        if ($summary === null || trim($summary) === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', trim($summary)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));

        return array_slice($parts === [] ? [trim($summary)] : $parts, 0, 3);
    }

    /**
     * Compact visual signals for the memo (cadence strip, format mix, best slot).
     *
     * @param  array<string, mixed>  $facts
     * @return array{
     *     cadence_days: list<array{label: string, posted: bool}>,
     *     own_posts_7d: int|null,
     *     rival_median_posts_7d: int|null,
     *     rival_cadence_label: string|null,
     *     format_mix: list<array{label: string, count: int}>,
     *     best_slot_label: string|null,
     *     best_slot_sample_count: int|null
     * }
     */
    private function signals(array $facts): array
    {
        $own = is_array($facts['own'] ?? null) ? $facts['own'] : [];
        $ownCadence = is_array($own['cadence'] ?? null) ? $own['cadence'] : [];
        $posts7 = isset($own['posts_last_7d_count'])
            ? (int) $own['posts_last_7d_count']
            : (isset($ownCadence['posts_last_7d']) ? (int) $ownCadence['posts_last_7d'] : null);
        $postedDays = is_array($ownCadence['posted_days_last_7'] ?? null)
            ? $ownCadence['posted_days_last_7']
            : (is_array($own['posted_days_last_7'] ?? null) ? $own['posted_days_last_7'] : null);

        // Match facts cadence window: the previous 7 London calendar days (not today).
        $cadenceDays = [];
        $today = CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfDay();
        $hasDayKeys = is_array($postedDays) && $postedDays !== [];
        for ($i = 7; $i >= 1; $i--) {
            $day = $today->subDays($i);
            $key = $day->toDateString();
            $posted = false;
            if ($hasDayKeys) {
                $posted = in_array($key, $postedDays, true)
                    || (bool) ($postedDays[$key] ?? false);
            } elseif ($posts7 !== null) {
                $posted = (7 - $i) < min(7, max(0, $posts7));
            }
            $cadenceDays[] = [
                'label' => $day->format('D'),
                'posted' => $posted,
            ];
        }

        $formatMix = [];
        $rawMix = is_array($own['posts_last_7d_by_format'] ?? null)
            ? $own['posts_last_7d_by_format']
            : (is_array($facts['format_mix']['own_7d'] ?? null) ? $facts['format_mix']['own_7d'] : []);
        foreach ($rawMix as $label => $count) {
            if (! is_string($label) || ! is_numeric($count) || (int) $count <= 0) {
                continue;
            }
            $formatMix[] = ['label' => $label, 'count' => (int) $count];
        }

        $slot = is_array($facts['best_times']['today_slot'] ?? null)
            ? $facts['best_times']['today_slot']
            : null;
        $slotLabel = null;
        $sampleCount = null;
        if (is_array($slot)) {
            $sampleCount = isset($slot['sample_count']) ? (int) $slot['sample_count'] : null;
            $start = is_string($slot['start'] ?? null) ? $slot['start'] : null;
            $end = is_string($slot['end'] ?? null) ? $slot['end'] : null;
            $label = is_string($slot['label'] ?? null) ? $slot['label'] : null;
            $slotLabel = $label ?: (($start && $end) ? $start.'-'.$end : null);
        }

        if ($posts7 === null && $cadenceDays !== []) {
            $anyPosted = false;
            foreach ($cadenceDays as $day) {
                if (! empty($day['posted'])) {
                    $anyPosted = true;
                    break;
                }
            }
            if (! $anyPosted) {
                $posts7 = 0;
            }
        }

        $rivalMedian = $this->rivalMedianPosts7d($facts);
        $rivalLabel = null;
        if ($rivalMedian !== null) {
            $rivalLabel = 'Rival median '.$this->pluralise($rivalMedian, 'post').' / 7 days';
        }

        return [
            'cadence_days' => $cadenceDays,
            'own_posts_7d' => $posts7,
            'rival_median_posts_7d' => $rivalMedian,
            'rival_cadence_label' => $rivalLabel,
            'format_mix' => $formatMix,
            'best_slot_label' => $slotLabel,
            'best_slot_sample_count' => $sampleCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function rivalMedianPosts7d(array $facts): ?int
    {
        $counts = [];
        $ownHandle = $this->normaliseHandle((string) (is_array($facts['own'] ?? null) ? ($facts['own']['handle'] ?? '') : ''));

        $rows = is_array($facts['cadence'] ?? null) ? $facts['cadence'] : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $handle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($handle === '' || ($ownHandle !== '' && $handle === $ownHandle)) {
                continue;
            }
            $counts[] = (int) ($row['posts_last_7d'] ?? 0);
        }

        if ($counts === []) {
            foreach (is_array($facts['competitors'] ?? null) ? $facts['competitors'] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $counts[] = (int) ($row['posts_last_7d_count'] ?? 0);
            }
        }

        $counts = array_values(array_filter($counts, fn (int $n): bool => $n >= 0));
        if ($counts === []) {
            return null;
        }

        sort($counts);
        $mid = (int) floor((count($counts) - 1) / 2);

        return (int) $counts[$mid];
    }

    private function normaliseHandle(string $handle): string
    {
        return mb_strtolower(ltrim(trim($handle), '@'));
    }

    /**
     * @return array{
     *     cadence_days: list<array{label: string, posted: bool}>,
     *     own_posts_7d: int|null,
     *     rival_median_posts_7d: int|null,
     *     rival_cadence_label: string|null,
     *     format_mix: list<array{label: string, count: int}>,
     *     best_slot_label: string|null,
     *     best_slot_sample_count: int|null
     * }
     */
    private function emptySignals(): array
    {
        return [
            'cadence_days' => [],
            'own_posts_7d' => null,
            'rival_median_posts_7d' => null,
            'rival_cadence_label' => null,
            'format_mix' => [],
            'best_slot_label' => null,
            'best_slot_sample_count' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $facts
     */
    private function resolveHeadline(array $payload, array $facts): ?string
    {
        $headline = is_string($payload['headline'] ?? null) ? trim($payload['headline']) : '';
        if ($headline !== '' && ! preg_match('/^here is today\'s plan\.?$/i', $headline)) {
            return $headline;
        }

        $own = is_array($facts['own'] ?? null) ? $facts['own'] : null;
        $daysSince = is_array($own) ? $own['days_since_last_post'] : null;
        $comp7 = (int) ($facts['format_mix']['competitor_posts_7d'] ?? 0);
        if ($daysSince !== null && (int) $daysSince >= 3 && $comp7 > 0) {
            return (int) $daysSince.' days quiet while rivals posted '.$this->pluralise($comp7, 'time').'.';
        }

        return $headline !== '' ? $headline : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $facts
     */
    private function resolveSummary(array $payload, array $overrides, array $facts): ?string
    {
        if (filled($overrides['summary'] ?? null) && is_string($overrides['summary'])) {
            return trim($overrides['summary']);
        }

        $ownSummary = is_string($payload['own_summary'] ?? null) ? trim($payload['own_summary']) : '';
        $competitorSummary = is_string($payload['competitor_summary'] ?? null)
            ? trim($payload['competitor_summary'])
            : '';

        if ($ownSummary !== '' && $competitorSummary !== '') {
            return $this->joinSummaryParts($ownSummary, $competitorSummary);
        }

        if ($ownSummary !== '') {
            return $ownSummary;
        }

        if ($competitorSummary !== '') {
            return $competitorSummary;
        }

        if (filled($payload['summary'] ?? null) && is_string($payload['summary'])) {
            return trim($payload['summary']);
        }

        $own = is_array($facts['own'] ?? null) ? $facts['own'] : null;
        $daysSince = is_array($own) ? $own['days_since_last_post'] : null;
        $comp7 = (int) ($facts['format_mix']['competitor_posts_7d'] ?? 0);
        if ($daysSince !== null && (int) $daysSince >= 3 && $comp7 > 0) {
            $handle = (string) ($own['handle'] ?? 'your account');

            return '@'.$handle.' has been quiet for '.$this->pluralise((int) $daysSince, 'day')
                .' while rivals published '.$this->pluralise($comp7, 'post').' this week.';
        }

        return null;
    }

    /**
     * Join interpretation + rival clause without repeating "competitors published N posts".
     */
    private function joinSummaryParts(string $ownSummary, string $competitorSummary): string
    {
        $ownLower = mb_strtolower($ownSummary);
        $alreadyCounts = str_contains($ownLower, 'competitors published')
            || str_contains($ownLower, 'rivals published')
            || str_contains($ownLower, 'rivals posted');

        $comp = $competitorSummary;
        if ($alreadyCounts) {
            // Keep the distinctive strongest-post clause; drop the repeated volume sentence.
            if (preg_match('/\bStrongest recent post:.+$/u', $comp, $match) === 1) {
                $comp = trim($match[0]);
            } else {
                $comp = (string) preg_replace(
                    '/^Competitors published \d+ posts? in the last 7 days(?:, including \d+ posts? in the last 24 hours)?\.?\s*/iu',
                    '',
                    $comp,
                );
                $comp = trim($comp);
            }
        }

        if ($comp === '') {
            return $ownSummary;
        }

        return rtrim($ownSummary, ' ').' '.$comp;
    }

    /**
     * Agent "what happened" for own account: interpretive prose + at most 2 evidence thumbs.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $facts
     * @return list<array{text: string, posts: list<array<string, mixed>>}>
     */
    private function interpretiveOwnActivity(array $payload, array $facts): array
    {
        $text = is_string($payload['own_summary'] ?? null) ? trim($payload['own_summary']) : '';
        if ($text === '') {
            return [];
        }

        // Quiet-week own summaries are prose only - do not bolt on stale grid thumbs
        // that compete with the labelled evidence under What happened.
        $posts7 = (int) ($facts['own']['posts_last_7d_count'] ?? 0);
        $posts = [];
        if ($posts7 > 0) {
            $posts = $this->enrichPosts(
                array_slice(is_array($payload['own_last_7_days'] ?? null) ? $payload['own_last_7_days'] : [], 0, 2),
            );
        }

        return [[
            'text' => $text,
            'posts' => $posts,
        ]];
    }

    /**
     * Agent competitor prose: one interpretive block (or section bodies), not a stats row per rival.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $facts
     * @return list<array{text: string, handle: string|null, posts: list<array<string, mixed>>}>
     */
    private function interpretiveCompetitorActivity(array $payload, array $facts, ?string $narrative): array
    {
        $automation = is_array($payload['automation_overrides'] ?? null)
            ? $payload['automation_overrides']
            : [];
        $sections = is_array($automation['sections'] ?? null) ? $automation['sections'] : [];

        // One "What happened" block only - multiple matching section titles used to
        // repeat the same outlier thumbs under every paragraph.
        $body = $this->bestWhatHappenedSectionBody($sections);
        if ($body === '') {
            $body = is_string($payload['competitor_summary'] ?? null)
                ? trim($payload['competitor_summary'])
                : '';
        }

        if ($body === '') {
            return [];
        }

        return [[
            'text' => $body,
            'handle' => null,
            'posts' => $this->topCompetitorEvidenceThumbs($payload, $facts, $narrative, $body),
        ]];
    }

    /**
     * Prefer an explicit "What happened" section; otherwise the longest rival/week body.
     *
     * @param  list<mixed>  $sections
     */
    private function bestWhatHappenedSectionBody(array $sections): string
    {
        $bestHappened = '';
        $bestOther = '';

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $title = mb_strtolower(trim((string) ($section['title'] ?? '')));
            $body = trim((string) ($section['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            if (str_contains($title, 'happened')) {
                if (mb_strlen($body) > mb_strlen($bestHappened)) {
                    $bestHappened = $body;
                }

                continue;
            }
            if (
                str_contains($title, 'competitor')
                || str_contains($title, 'rival')
                || str_contains($title, 'this week')
            ) {
                if (mb_strlen($body) > mb_strlen($bestOther)) {
                    $bestOther = $body;
                }
            }
        }

        return $bestHappened !== '' ? $bestHappened : $bestOther;
    }

    /**
     * At most 2 standout thumbs as evidence under competitor prose.
     * Prefer posts named in the section body; skip posts the opening narrative already used.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private function topCompetitorEvidenceThumbs(
        array $payload,
        array $facts,
        ?string $narrative,
        string $sectionBody,
    ): array {
        $candidates = [];
        $walker = function (mixed $value) use (&$candidates, &$walker): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['post_id'], $value['times_usual']) && is_numeric($value['times_usual'])) {
                $candidates[] = $value;
            }
            foreach ($value as $child) {
                $walker($child);
            }
        };
        $walker($facts['top_competitor_hit_7d'] ?? null);
        $walker($payload['competitor_moves'] ?? null);
        $walker($facts['competitors'] ?? null);

        $narrativeLower = mb_strtolower((string) $narrative);
        $sectionLower = mb_strtolower($sectionBody);

        $byId = [];
        foreach ($candidates as $post) {
            $id = (int) ($post['post_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $existing = $byId[$id] ?? null;
            if ($existing === null || (float) ($post['times_usual'] ?? 0) > (float) ($existing['times_usual'] ?? 0)) {
                $byId[$id] = $post;
            }
        }
        $candidates = array_values($byId);

        $candidates = array_values(array_filter(
            $candidates,
            function (array $post) use ($narrativeLower): bool {
                $handle = mb_strtolower(ltrim((string) ($post['handle'] ?? ''), '@'));
                $format = mb_strtolower((string) ($post['format'] ?? ''));
                if ($handle === '' || $narrativeLower === '') {
                    return true;
                }
                // Drop posts the opening narrative already argued from (same handle + format or Nx).
                $nx = isset($post['times_usual'])
                    ? rtrim(rtrim(number_format((float) $post['times_usual'], 1), '0'), '.')
                    : '';
                $mentionsHandle = str_contains($narrativeLower, '@'.$handle)
                    || str_contains($narrativeLower, $handle);
                if (! $mentionsHandle) {
                    return true;
                }
                if ($format !== '' && str_contains($narrativeLower, $format) && $nx !== '' && str_contains($narrativeLower, $nx)) {
                    return false;
                }

                return ! ($nx !== '' && str_contains($narrativeLower, $nx));
            },
        ));

        usort($candidates, function (array $a, array $b) use ($sectionLower): int {
            $aHandle = mb_strtolower(ltrim((string) ($a['handle'] ?? ''), '@'));
            $bHandle = mb_strtolower(ltrim((string) ($b['handle'] ?? ''), '@'));
            $aInSection = $aHandle !== '' && str_contains($sectionLower, $aHandle) ? 1 : 0;
            $bInSection = $bHandle !== '' && str_contains($sectionLower, $bHandle) ? 1 : 0;
            if ($aInSection !== $bInSection) {
                return $bInSection <=> $aInSection;
            }

            return ((float) $b['times_usual']) <=> ((float) $a['times_usual']);
        });

        // Prefer two different handles so the row does not look like the same post twice.
        $picked = [];
        $seenHandles = [];
        foreach ($candidates as $post) {
            $handle = mb_strtolower(ltrim((string) ($post['handle'] ?? ''), '@'));
            if ($handle !== '' && isset($seenHandles[$handle]) && count($picked) > 0) {
                continue;
            }
            $picked[] = $post;
            if ($handle !== '') {
                $seenHandles[$handle] = true;
            }
            if (count($picked) >= 2) {
                break;
            }
        }
        if (count($picked) < 2) {
            foreach ($candidates as $post) {
                $id = (int) ($post['post_id'] ?? 0);
                $already = false;
                foreach ($picked as $row) {
                    if ((int) ($row['post_id'] ?? 0) === $id) {
                        $already = true;
                        break;
                    }
                }
                if ($already) {
                    continue;
                }
                $picked[] = $post;
                if (count($picked) >= 2) {
                    break;
                }
            }
        }

        return $this->enrichPosts($picked);
    }

    /**
     * Facts-only own section with post thumbnails. Interpretation lives in summary.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $facts
     * @return list<array{text: string, posts: list<array<string, mixed>>}>
     */
    private function ownActivityBullets(array $payload, array $facts): array
    {
        $own = is_array($facts['own'] ?? null) ? $facts['own'] : null;
        $recentPosts = $this->enrichPosts(
            array_slice(is_array($payload['own_last_7_days'] ?? null) ? $payload['own_last_7_days'] : [], 0, 2),
        );

        if (! is_array($own) && $recentPosts === []) {
            return [[
                'text' => 'Not enough of your own posts are stored yet.',
                'posts' => [],
            ]];
        }

        $handle = is_array($own) ? (string) ($own['handle'] ?? '') : '';
        if ($handle === '') {
            $brandHandles = is_array($facts['brand']['own_handles'] ?? null)
                ? $facts['brand']['own_handles']
                : [];
            $handle = is_string($brandHandles[0] ?? null) && $brandHandles[0] !== ''
                ? (string) $brandHandles[0]
                : 'your account';
        }
        $posts7 = is_array($own) ? (int) ($own['posts_last_7d_count'] ?? 0) : count($payload['own_last_7_days'] ?? []);
        $format7 = is_array($own) ? ($own['posts_last_7d_by_format'] ?? []) : [];
        $formatBit7 = $this->formatMixPhrase(is_array($format7) ? $format7 : []);
        $followers = is_array($own) ? $own['followers_now'] : null;
        $change7 = is_array($own) ? ($own['followers_change_7d'] ?? null) : null;
        $changeLabel = is_array($change7) ? trim((string) ($change7['label'] ?? '')) : '';
        $daysSince = is_array($own) ? $own['days_since_last_post'] : null;

        $bits = ['@'.$handle];
        $bits[] = $this->pluralise($posts7, 'post').' in the last 7 days'
            .($formatBit7 !== '' ? ' ('.$formatBit7.')' : '');
        if ($followers !== null) {
            $bits[] = 'followers '.$followers
                .($changeLabel !== '' ? ' ('.$changeLabel.')' : '');
        }
        if ($daysSince !== null) {
            $bits[] = 'last post '.$this->pluralise((int) $daysSince, 'day').' ago';
        }

        return [[
            'text' => implode(' · ', $bits).'.',
            'posts' => $recentPosts,
        ]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{text: string, handle: string|null, posts: list<array<string, mixed>>}>
     */
    private function competitorActivity(array $payload): array
    {
        $moves = is_array($payload['competitor_moves'] ?? null) ? $payload['competitor_moves'] : [];
        $items = [];
        $zeroHandles = [];

        foreach ($moves as $row) {
            if (! is_array($row)) {
                continue;
            }

            $handle = isset($row['handle']) ? (string) $row['handle'] : null;
            if ($handle === null || $handle === '') {
                continue;
            }

            if ($row['sync_failed'] ?? false) {
                $items[] = [
                    'text' => '@'.$handle.' could not be refreshed. Do not treat that as silence.',
                    'handle' => $handle,
                    'posts' => [],
                ];

                continue;
            }

            $count7 = (int) ($row['posts_last_7d_count'] ?? 0);
            $isZero = ($row['sync_empty'] ?? false)
                || ($row['quiet'] ?? false)
                || $count7 === 0;

            if ($isZero) {
                $zeroHandles[] = '@'.$handle;

                continue;
            }

            $standouts = is_array($row['standout_winners'] ?? null) ? $row['standout_winners'] : [];
            $recent = is_array($row['posts_last_7d'] ?? null) ? $row['posts_last_7d'] : [];
            // Memo evidence: at most two standout thumbs in one horizontal row.
            $posts = $this->enrichPosts(array_slice($standouts !== [] ? $standouts : $recent, 0, 2));
            $change = is_array($row['followers_change_7d'] ?? null)
                ? trim((string) ($row['followers_change_7d']['label'] ?? ''))
                : '';

            $text = '@'.$handle.' posted '.$this->pluralise($count7, 'time').' in the last 7 days.';
            if ($change !== '') {
                $text .= ' Followers: '.$change.'.';
            }
            if ($posts !== []) {
                $top = $posts[0];
                $format = (string) ($top['format'] ?? 'post');
                $label = (string) ($top['times_usual_label'] ?? '');
                $when = (string) ($top['posted_label'] ?? 'recently');
                $text .= ' Notable: '.$format.' from '.$when
                    .($label !== '' ? ' at '.$label : '').'.';
            }

            $items[] = [
                'text' => $text,
                'handle' => $handle,
                'posts' => $posts,
            ];
        }

        if ($zeroHandles !== []) {
            $items[] = [
                'text' => implode(', ', $zeroHandles).' posted 0 times in the last 7 days.',
                'handle' => null,
                'posts' => [],
            ];
        }

        if ($items === []) {
            return [[
                'text' => 'No notable competitor moves in the stored window yet.',
                'handle' => null,
                'posts' => [],
            ]];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $facts
     * @return list<array{number: int, title: string, why: string, evidence: list<array<string, mixed>>}>
     */
    private function resolveSuggestions(array $payload, array $overrides, array $facts): array
    {
        if (is_array($overrides['suggestions'] ?? null)) {
            $out = [];
            foreach (array_values($overrides['suggestions']) as $index => $row) {
                if (! is_array($row) || $this->isUnusedWeeklyIdeaSuggestion($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                $why = trim((string) ($row['why'] ?? ''));
                if ($title === '' || $why === '') {
                    continue;
                }
                $evidenceIds = is_array($row['related_post_ids'] ?? null)
                    ? $row['related_post_ids']
                    : [];
                $expected = trim((string) ($row['expected'] ?? ''));
                $evidence = array_slice($this->evidencePosts($facts, $payload, $evidenceIds), 0, 1);
                $out[] = [
                    'number' => count($out) + 1,
                    'title' => $title,
                    'why' => $why,
                    'expected' => $expected !== '' ? $expected : null,
                    'evidence' => $evidence,
                ];
            }

            return array_slice($out, 0, 3);
        }

        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        $out = [];

        foreach (array_values($actions) as $action) {
            if (! is_array($action) || $this->isUnusedWeeklyIdeaSuggestion($action)) {
                continue;
            }
            $title = trim((string) ($action['title'] ?? ''));
            $why = trim((string) ($action['why'] ?? ''));
            if ($title === '') {
                continue;
            }
            if ($why === '') {
                $why = 'Not enough stored figures to ground this suggestion yet.';
            }

            $ids = is_array($action['related_post_ids'] ?? null) ? $action['related_post_ids'] : [];
            $inspiration = is_array($action['inspiration'] ?? null) ? $action['inspiration'] : [];
            $evidence = $this->evidencePosts($facts, $payload, $ids);
            if ($evidence === [] && $inspiration !== []) {
                $evidence = $this->enrichPosts($inspiration);
            }
            $expected = trim((string) ($action['expected'] ?? ''));

            $out[] = [
                'number' => count($out) + 1,
                'title' => $title,
                'why' => $why,
                'expected' => $expected !== '' ? $expected : null,
                'evidence' => array_slice($evidence, 0, 1),
            ];
        }

        if ($out === []) {
            return [[
                'number' => 1,
                'title' => 'Not enough stored data for grounded suggestions yet.',
                'why' => 'Sync competitors and wait for the morning brief before expecting numbered actions with figures.',
                'expected' => null,
                'evidence' => [],
            ]];
        }

        return array_slice($out, 0, 3);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isUnusedWeeklyIdeaSuggestion(array $row): bool
    {
        $blob = mb_strtolower(implode(' ', [
            (string) ($row['title'] ?? ''),
            (string) ($row['why'] ?? ''),
            (string) ($row['how'] ?? ''),
        ]));

        return str_contains($blob, 'post this next')
            || (str_contains($blob, 'unused') && str_contains($blob, 'idea'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{line: string|null, failed_handles: list<string>}
     */
    private function freshnessSummary(array $payload): array
    {
        $rows = is_array($payload['data_freshness'] ?? null) ? $payload['data_freshness'] : [];
        $latest = null;
        $failed = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $handle = isset($row['handle']) ? (string) $row['handle'] : '';
            if ($row['sync_failed'] ?? false) {
                if ($handle !== '') {
                    $failed[] = '@'.$handle;
                }

                continue;
            }

            $synced = isset($row['last_synced_at']) ? (string) $row['last_synced_at'] : '';
            if ($synced === '') {
                continue;
            }

            try {
                $at = CarbonImmutable::parse($synced)->timezone(DashboardMath::TIMEZONE);
            } catch (\Throwable) {
                continue;
            }

            if ($latest === null || $at->greaterThan($latest)) {
                $latest = $at;
            }
        }

        $line = null;
        if ($latest !== null) {
            $line = 'Data last updated '.$latest->format('j M, H:i');
        }

        if ($failed !== []) {
            $failedBit = 'Refresh failed for '.implode(', ', array_values(array_unique($failed))).'.';
            $line = $line !== null ? $line.'. '.$failedBit : $failedBit;
        }

        return [
            'line' => $line,
            'failed_handles' => array_values(array_unique($failed)),
        ];
    }

    /**
     * @param  array<string, int|string>  $mix
     */
    private function formatMixPhrase(array $mix): string
    {
        $parts = [];
        foreach ($mix as $format => $count) {
            $n = (int) $count;
            if ($n <= 0) {
                continue;
            }
            $parts[] = $n.' '.$format.($n === 1 ? '' : (str_ends_with((string) $format, 's') ? '' : 's'));
        }

        return implode(', ', $parts);
    }

    /**
     * @param  list<mixed>  $posts
     * @return list<array<string, mixed>>
     */
    private function enrichPosts(array $posts): array
    {
        $ids = [];
        foreach ($posts as $post) {
            if (is_array($post) && isset($post['post_id'])) {
                $ids[] = (int) $post['post_id'];
            }
        }

        /** @var Collection<int, Post> $models */
        $models = $ids === []
            ? collect()
            : Post::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');

        $out = [];
        foreach ($posts as $post) {
            if (! is_array($post)) {
                continue;
            }
            $id = isset($post['post_id']) ? (int) $post['post_id'] : 0;
            $model = $id > 0 ? $models->get($id) : null;
            $thumbnail = $post['thumbnail_url'] ?? null;
            if ((! is_string($thumbnail) || $thumbnail === '') && $model !== null) {
                $thumbnail = $model->cover_url;
            }

            $out[] = [
                'post_id' => $id > 0 ? $id : null,
                'url' => $post['url'] ?? ($model?->url),
                'snitch_url' => $id > 0 ? McpAppUrls::feedPost($id) : null,
                'handle' => $post['handle'] ?? null,
                'format' => $post['format'] ?? null,
                'posted_label' => $post['posted_label'] ?? null,
                'hook' => $post['hook'] ?? null,
                'caption' => $post['caption'] ?? null,
                'times_usual' => $post['times_usual'] ?? null,
                'times_usual_label' => $post['times_usual_label'] ?? null,
                'thumbnail_url' => is_string($thumbnail) && $thumbnail !== '' ? $thumbnail : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $payload
     * @param  list<mixed>  $ids
     * @return list<array<string, mixed>>
     */
    private function evidencePosts(array $facts, array $payload, array $ids): array
    {
        $wanted = array_values(array_unique(array_map('intval', $ids)));
        if ($wanted === []) {
            return [];
        }

        $found = [];
        $walker = function (mixed $value) use (&$found, &$walker, $wanted): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['post_id']) && in_array((int) $value['post_id'], $wanted, true)) {
                $found[(int) $value['post_id']] = $value;
            }
            foreach ($value as $child) {
                $walker($child);
            }
        };
        $walker($facts);
        $walker($payload);

        return $this->enrichPosts(array_values($found));
    }

    private function pluralise(int $n, string $word): string
    {
        if ($n === 1) {
            return '1 '.$word;
        }

        if ($word === 'time') {
            return $n.' times';
        }

        if ($word === 'day') {
            return $n.' days';
        }

        if ($word === 'post') {
            return $n.' posts';
        }

        return $n.' '.$word.'s';
    }
}
