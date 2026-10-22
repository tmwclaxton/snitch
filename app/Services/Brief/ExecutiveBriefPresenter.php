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

        $summary = $this->resolveSummary($payload, $overrides, $facts);
        $ownActivity = $this->ownActivityBullets($payload, $facts);
        $competitorActivity = $this->competitorActivity($payload);
        $suggestions = $this->resolveSuggestions($payload, $overrides, $facts);
        $insufficient = $summary === null
            || ($ownActivity === [] && $competitorActivity === [] && $suggestions === []);

        return [
            'empty' => false,
            'empty_reason' => null,
            'brief_date' => $brief->brief_date?->toDateString(),
            'generated_at' => $brief->generated_at?->timezone(DashboardMath::TIMEZONE)->toIso8601String(),
            'headline' => $this->resolveHeadline($payload, $facts),
            'summary' => $summary,
            'own_activity' => $ownActivity,
            'competitor_activity' => $competitorActivity,
            'suggestions' => $suggestions,
            'signals' => $this->signals($facts),
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
            'summary' => null,
            'own_activity' => [],
            'competitor_activity' => [],
            'suggestions' => [],
            'signals' => $this->emptySignals(),
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
     * Compact visual signals for the memo (cadence strip, format mix, best slot).
     *
     * @param  array<string, mixed>  $facts
     * @return array{
     *     cadence_days: list<array{label: string, posted: bool}>,
     *     own_posts_7d: int|null,
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

        return [
            'cadence_days' => $cadenceDays,
            'own_posts_7d' => $posts7,
            'format_mix' => $formatMix,
            'best_slot_label' => $slotLabel,
            'best_slot_sample_count' => $sampleCount,
        ];
    }

    /**
     * @return array{
     *     cadence_days: list<array{label: string, posted: bool}>,
     *     own_posts_7d: int|null,
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
            array_slice(is_array($payload['own_last_7_days'] ?? null) ? $payload['own_last_7_days'] : [], 0, 4),
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
            $posts = $this->enrichPosts(array_slice($standouts !== [] ? $standouts : $recent, 0, 3));
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
                $out[] = [
                    'number' => count($out) + 1,
                    'title' => $title,
                    'why' => $why,
                    'evidence' => $this->evidencePosts($facts, $payload, $evidenceIds),
                ];
            }

            return array_slice($out, 0, 5);
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

            $out[] = [
                'number' => count($out) + 1,
                'title' => $title,
                'why' => $why,
                'evidence' => $evidence,
            ];
        }

        if ($out === []) {
            return [[
                'number' => 1,
                'title' => 'Not enough stored data for grounded suggestions yet.',
                'why' => 'Sync competitors and wait for the morning brief before expecting numbered actions with figures.',
                'evidence' => [],
            ]];
        }

        return array_slice($out, 0, 5);
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
