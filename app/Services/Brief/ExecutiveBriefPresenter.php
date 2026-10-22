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
     *     freshness: list<array{handle: string|null, last_synced_at: string|null, label: string}>,
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

        $summary = $this->resolveSummary($payload, $overrides);
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
            'headline' => is_string($payload['headline'] ?? null) ? $payload['headline'] : null,
            'summary' => $summary,
            'own_activity' => $ownActivity,
            'competitor_activity' => $competitorActivity,
            'suggestions' => $suggestions,
            'freshness' => $this->freshnessRows($payload),
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
            'freshness' => [],
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     */
    private function resolveSummary(array $payload, array $overrides): ?string
    {
        if (filled($overrides['summary'] ?? null) && is_string($overrides['summary'])) {
            return trim($overrides['summary']);
        }

        if (filled($payload['summary'] ?? null) && is_string($payload['summary'])) {
            return trim($payload['summary']);
        }

        $parts = array_values(array_filter([
            is_string($payload['own_summary'] ?? null) ? trim($payload['own_summary']) : null,
            is_string($payload['competitor_summary'] ?? null) ? trim($payload['competitor_summary']) : null,
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        if ($parts === []) {
            return null;
        }

        return implode(' ', array_slice($parts, 0, 2));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $facts
     * @return list<array{text: string, posts: list<array<string, mixed>>}>
     */
    private function ownActivityBullets(array $payload, array $facts): array
    {
        $own = is_array($facts['own'] ?? null) ? $facts['own'] : null;
        $bullets = [];

        if (! is_array($own)) {
            $posts = is_array($payload['own_last_7_days'] ?? null) ? $payload['own_last_7_days'] : [];
            if ($posts === []) {
                return [[
                    'text' => 'Not enough of your own posts are stored yet to describe recent activity.',
                    'posts' => [],
                ]];
            }
        }

        $handle = is_array($own) ? (string) ($own['handle'] ?? 'your account') : 'your account';
        $posts7 = is_array($own) ? (int) ($own['posts_last_7d_count'] ?? 0) : count($payload['own_last_7_days'] ?? []);
        $format7 = is_array($own) ? ($own['posts_last_7d_by_format'] ?? []) : [];
        $format30 = is_array($own) ? ($own['posts_last_30d_by_format'] ?? []) : [];
        $posts30 = is_array($format30) ? array_sum(array_map('intval', $format30)) : 0;

        $formatBit7 = $this->formatMixPhrase(is_array($format7) ? $format7 : []);
        $formatBit30 = $this->formatMixPhrase(is_array($format30) ? $format30 : []);

        $volumeText = '@'.$handle.' posted '.$this->pluralise($posts7, 'time').' in the last 7 days'
            .($formatBit7 !== '' ? ' ('.$formatBit7.')' : '')
            .' and '.$this->pluralise($posts30, 'time').' in the last 30 days'
            .($formatBit30 !== '' ? ' ('.$formatBit30.')' : '').'.';

        $recentPosts = $this->enrichPosts(
            array_slice(is_array($payload['own_last_7_days'] ?? null) ? $payload['own_last_7_days'] : [], 0, 4),
        );
        $bullets[] = [
            'text' => $volumeText,
            'posts' => $recentPosts,
        ];

        if ($recentPosts !== []) {
            $perfParts = [];
            foreach (array_slice($recentPosts, 0, 3) as $post) {
                $label = (string) ($post['times_usual_label'] ?? '');
                $format = (string) ($post['format'] ?? 'post');
                $when = (string) ($post['posted_label'] ?? 'recently');
                if ($label !== '' && str_contains(strtolower($label), 'times')) {
                    $perfParts[] = $format.' from '.$when.' at '.$label;
                } else {
                    $perfParts[] = $format.' from '.$when
                        .($label !== '' ? ' ('.$label.')' : '');
                }
            }
            $bullets[] = [
                'text' => 'Recent posts versus your usual: '.implode('; ', $perfParts).'.',
                'posts' => $recentPosts,
            ];
        }

        $change7 = is_array($own) ? ($own['followers_change_7d'] ?? null) : null;
        $followers = is_array($own) ? $own['followers_now'] : null;
        if ($followers !== null) {
            $changeLabel = is_array($change7) ? trim((string) ($change7['label'] ?? '')) : '';
            $bullets[] = [
                'text' => 'Followers: '.$followers
                    .($changeLabel !== '' ? ' ('.$changeLabel.')' : '.')
                    .($changeLabel !== '' && ! str_ends_with($changeLabel, '.') ? '.' : ''),
                'posts' => [],
            ];
        }

        $daysSince = is_array($own) ? $own['days_since_last_post'] : null;
        if ($daysSince !== null && (int) $daysSince >= 2) {
            $bullets[] = [
                'text' => 'Quiet days: last post was '.$this->pluralise((int) $daysSince, 'day').' ago.',
                'posts' => [],
            ];
        }

        return $bullets;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{text: string, handle: string|null, posts: list<array<string, mixed>>}>
     */
    private function competitorActivity(array $payload): array
    {
        $moves = is_array($payload['competitor_moves'] ?? null) ? $payload['competitor_moves'] : [];
        $items = [];

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

            if ($row['sync_empty'] ?? false) {
                $items[] = [
                    'text' => '@'.$handle.' returned no posts. Check the handle or swap in a more active account.',
                    'handle' => $handle,
                    'posts' => [],
                ];

                continue;
            }

            if ($row['quiet'] ?? false) {
                $days = (int) ($row['days_since_last_post'] ?? 14);
                $items[] = [
                    'text' => '@'.$handle.' has been quiet for '.$this->pluralise($days, 'day').'.',
                    'handle' => $handle,
                    'posts' => [],
                ];

                continue;
            }

            $standouts = is_array($row['standout_winners'] ?? null) ? $row['standout_winners'] : [];
            $recent = is_array($row['posts_last_7d'] ?? null) ? $row['posts_last_7d'] : [];
            $posts = $this->enrichPosts(array_slice($standouts !== [] ? $standouts : $recent, 0, 3));
            $count7 = (int) ($row['posts_last_7d_count'] ?? count($recent));
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
                if (! is_array($row)) {
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
                    'number' => $index + 1,
                    'title' => $title,
                    'why' => $why,
                    'evidence' => $this->evidencePosts($facts, $payload, $evidenceIds),
                ];
            }

            return array_slice($out, 0, 5);
        }

        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        $out = [];

        foreach (array_values($actions) as $index => $action) {
            if (! is_array($action)) {
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
                'number' => $index + 1,
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
     * @param  array<string, mixed>  $payload
     * @return list<array{handle: string|null, last_synced_at: string|null, label: string}>
     */
    private function freshnessRows(array $payload): array
    {
        $rows = [];
        foreach ($payload['data_freshness'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $handle = isset($row['handle']) ? (string) $row['handle'] : null;
            $synced = isset($row['last_synced_at']) ? (string) $row['last_synced_at'] : null;
            $status = (string) ($row['sync_status'] ?? '');
            $label = $synced !== null && $synced !== ''
                ? 'Last synced '.$synced
                : 'Sync time unknown';
            if ($row['sync_failed'] ?? false) {
                $label = 'Refresh failed';
            } elseif ($row['sync_empty'] ?? false) {
                $label = 'Synced empty';
            } elseif ($status !== '') {
                $label .= ' ('.$status.')';
            }
            $rows[] = [
                'handle' => $handle,
                'last_synced_at' => $synced,
                'label' => $label,
            ];
        }

        return $rows;
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
