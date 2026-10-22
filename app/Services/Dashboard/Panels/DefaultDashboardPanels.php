<?php

namespace App\Services\Dashboard\Panels;

use App\Models\DailyBrief;
use App\Models\DashboardPanel;
use App\Models\User;
use App\Services\Mcp\McpReadService;

/**
 * Build a default curated panel set from stored user data (no scrapes).
 */
class DefaultDashboardPanels
{
    public function __construct(private McpReadService $reads) {}

    /**
     * @return list<array{position: int, type: string, title: string, payload: array<string, mixed>, source: string}>
     */
    public function forUser(User $user): array
    {
        $panels = [];
        $position = 0;

        $summary = $this->reads->accountSummary($user);
        if (isset($summary['followers_now']) && is_numeric($summary['followers_now'])) {
            $panels[] = $this->row($position++, DashboardPanel::TYPE_BIG_NUMBER, 'Followers', [
                'label' => 'Followers',
                'value' => number_format((int) $summary['followers_now']),
                'note' => isset($summary['tracker']['handle'])
                    ? '@'.$summary['tracker']['handle']
                    : null,
                'accent' => true,
            ]);
        }

        $growth7 = $summary['growth']['7d']['change'] ?? null;
        if (is_numeric($growth7)) {
            $change = (int) $growth7;
            $sign = $change > 0 ? '+' : '';
            $panels[] = $this->row($position++, DashboardPanel::TYPE_BIG_NUMBER, 'Follower change 7 days', [
                'label' => 'Follower change 7 days',
                'value' => $sign.number_format($change),
                'note' => 'Stored snapshots only',
            ]);
        }

        $brief = DailyBrief::query()
            ->where('user_id', $user->id)
            ->orderByDesc('brief_date')
            ->orderByDesc('id')
            ->first();
        if ($brief !== null && filled($brief->headline)) {
            $panels[] = $this->row($position++, DashboardPanel::TYPE_NOTE, 'Today', [
                'body' => (string) $brief->headline,
                'action_label' => 'Open Today',
                'action_href' => '/today',
            ]);
        }

        $topPosts = $this->reads->topPosts($user, 30, 1);
        $top = $topPosts[0] ?? null;
        if (is_array($top)) {
            $panels[] = $this->row($position++, DashboardPanel::TYPE_POST_CARD, 'Top post', [
                'post_id' => $top['post_id'] ?? null,
                'handle' => (string) ($top['handle'] ?? ''),
                'format' => $top['format'] ?? null,
                'url' => $top['url'] ?? null,
                'posted_at' => $top['posted_at'] ?? null,
                'caption' => $top['caption'] ?? null,
                'likes' => $top['likes'] ?? null,
                'comments' => $top['comments'] ?? null,
                'views' => $top['views'] ?? null,
                'times_usual_label' => $top['times_usual_label'] ?? null,
                'thumbnail_url' => null,
            ]);
        }

        $formatMix = $this->reads->formatMix($user, 30);
        $formatBars = $this->barSeriesFromCounts($formatMix['total'] ?? []);
        if ($formatBars !== null) {
            $panels[] = $this->row($position++, DashboardPanel::TYPE_BAR_CHART, 'Format mix 30 days', [
                'y_label' => 'Posts',
                'series' => [$formatBars],
            ]);
        }

        $times = $this->reads->postingTimes($user, 30);
        $timeBars = $this->barSeriesFromSlots($times['slots'] ?? []);
        if ($timeBars !== null) {
            $panels[] = $this->row($position++, DashboardPanel::TYPE_BAR_CHART, 'Best posting times', [
                'y_label' => 'Posts',
                'series' => [$timeBars],
            ]);
        }

        $growth = $this->reads->growthSeries($user, '30d');
        $line = $this->followerLineSeries($growth['metrics']['accounts'] ?? []);
        if ($line !== null) {
            $panels[] = $this->row($position++, DashboardPanel::TYPE_LINE_CHART, 'Follower growth', [
                'y_label' => 'Followers',
                'series' => $line,
            ]);
        }

        $gapItems = $this->gapRankedItems($this->reads->gaps($user));
        if ($gapItems !== []) {
            $panels[] = $this->row($position++, DashboardPanel::TYPE_RANKED_LIST, 'Quiet accounts', [
                'items' => $gapItems,
            ]);
        }

        return $panels;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{position: int, type: string, title: string, payload: array<string, mixed>, source: string}
     */
    private function row(int $position, string $type, string $title, array $payload): array
    {
        return [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'payload' => $payload,
            'source' => 'default',
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{name: string, points: list<array{x: string, y: int}>}|null
     */
    private function barSeriesFromCounts(array $counts): ?array
    {
        if ($counts === []) {
            return null;
        }

        $points = [];
        foreach ($counts as $label => $count) {
            if ((int) $count < 1) {
                continue;
            }
            $points[] = ['x' => (string) $label, 'y' => (int) $count];
        }

        if ($points === []) {
            return null;
        }

        return ['name' => 'Posts', 'points' => $points];
    }

    /**
     * @param  list<array{label?: string, count?: int}>  $slots
     * @return array{name: string, points: list<array{x: string, y: int}>}|null
     */
    private function barSeriesFromSlots(array $slots): ?array
    {
        $points = [];
        foreach (array_slice($slots, 0, 8) as $slot) {
            $count = (int) ($slot['count'] ?? 0);
            $label = (string) ($slot['label'] ?? '');
            if ($count < 1 || $label === '') {
                continue;
            }
            $points[] = ['x' => $label, 'y' => $count];
        }

        if ($points === []) {
            return null;
        }

        return ['name' => 'Posts', 'points' => $points];
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array{name: string, points: list<array{x: string, y: int|float|null}>}>|null
     */
    private function followerLineSeries(array $accounts): ?array
    {
        $series = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }
            $handle = (string) ($account['handle'] ?? '');
            $pointsRaw = $account['followers'] ?? [];
            if ($handle === '' || ! is_array($pointsRaw) || $pointsRaw === []) {
                continue;
            }

            $points = [];
            foreach ($pointsRaw as $point) {
                if (! is_array($point)) {
                    continue;
                }
                $x = $point['date'] ?? $point['x'] ?? null;
                $y = $point['value'] ?? $point['y'] ?? null;
                if ($x === null) {
                    continue;
                }
                $points[] = [
                    'x' => (string) $x,
                    'y' => is_numeric($y) ? $y + 0 : null,
                ];
            }

            $hasY = collect($points)->contains(fn (array $p): bool => is_numeric($p['y'] ?? null));
            if (! $hasY) {
                continue;
            }

            $series[] = [
                'name' => $handle,
                'points' => $points,
            ];

            if (count($series) >= 4) {
                break;
            }
        }

        return $series === [] ? null : $series;
    }

    /**
     * @param  list<array<string, mixed>>  $gaps
     * @return list<array{label: string, value: string, href: string|null}>
     */
    private function gapRankedItems(array $gaps): array
    {
        $items = [];
        foreach ($gaps as $gap) {
            if (! is_array($gap)) {
                continue;
            }
            $quiet = (bool) ($gap['quiet'] ?? false);
            $syncEmpty = (bool) ($gap['sync_empty'] ?? false);
            if (! $quiet && ! $syncEmpty) {
                continue;
            }

            $handle = (string) ($gap['handle'] ?? '');
            if ($handle === '') {
                continue;
            }

            $days = $gap['days_since_last_post'] ?? null;
            $value = $syncEmpty
                ? 'No posts stored'
                : (is_numeric($days) ? ((int) $days).' days quiet' : 'Quiet');

            $items[] = [
                'label' => '@'.$handle,
                'value' => $value,
                'href' => isset($gap['tracked_account_id'])
                    ? '/competitors/'.(int) $gap['tracked_account_id']
                    : null,
            ];

            if (count($items) >= 8) {
                break;
            }
        }

        return $items;
    }
}
