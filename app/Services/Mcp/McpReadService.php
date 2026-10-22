<?php

namespace App\Services\Mcp;

use App\Enums\TrackedAccountKind;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\DashboardMath;
use App\Services\Growth\GrowthMetricsBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Stored-data-only reads for MCP tools. Never queues Apify/TikHub scrapes.
 */
class McpReadService
{
    public function __construct(
        private DashboardMath $math,
        private GrowthMetricsBuilder $growth,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listTrackers(User $user): array
    {
        return $user->trackedAccounts()
            ->orderByDesc('is_own_account')
            ->orderBy('handle')
            ->get()
            ->map(fn (TrackedAccount $account): array => $this->trackerRow($account))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function accountSummary(User $user, ?string $handle = null): ?array
    {
        $account = $this->resolveAccount($user, $handle);
        if ($account === null) {
            return null;
        }

        $socialId = (int) ($account->social_account_id ?? 0);
        $now = CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfDay();
        $followersNow = $account->followers !== null ? (int) $account->followers : null;

        $growth = [
            '1d' => $this->followerDelta($socialId, $now->subDay(), $followersNow),
            '7d' => $this->followerDelta($socialId, $now->subDays(7), $followersNow),
            '30d' => $this->followerDelta($socialId, $now->subDays(30), $followersNow),
        ];

        $lastPost = null;
        if ($socialId > 0) {
            $post = Post::query()
                ->where('social_account_id', $socialId)
                ->whereNotNull('posted_at')
                ->orderByDesc('posted_at')
                ->first();

            if ($post !== null) {
                $history = $this->historyForSocial($socialId);
                $pi = $this->math->performanceIndex($post, $history);
                $lastPost = $this->postRow($post, $account, $pi);
            }
        }

        return [
            'tracker' => $this->trackerRow($account),
            'followers_now' => $followersNow,
            'growth' => $growth,
            'last_post' => $lastPost,
        ];
    }

    /**
     * @param  array{handle?: string|null, format?: string|null, since?: string|null, sort?: string|null, limit?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function competitorPosts(User $user, array $filters = []): array
    {
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 20)));
        $handle = isset($filters['handle']) ? $this->normaliseHandle((string) $filters['handle']) : null;
        $format = isset($filters['format']) ? trim((string) $filters['format']) : null;
        $since = null;
        if (isset($filters['since']) && is_string($filters['since']) && $filters['since'] !== '') {
            $since = CarbonImmutable::parse($filters['since'], DashboardMath::TIMEZONE)->startOfDay();
        }

        $accounts = $user->trackedAccounts()
            ->where('kind', TrackedAccountKind::Competitor)
            ->when($handle !== null && $handle !== '', fn ($q) => $q->whereRaw('LOWER(handle) = ?', [$handle]))
            ->get()
            ->keyBy('social_account_id');

        $socialIds = $accounts->keys()->filter()->map(fn ($id): int => (int) $id)->values()->all();
        if ($socialIds === []) {
            return [];
        }

        $posts = Post::query()
            ->whereIn('social_account_id', $socialIds)
            ->whereNotNull('posted_at')
            ->when($since !== null, fn ($q) => $q->where('posted_at', '>=', $since))
            ->orderByDesc('posted_at')
            ->limit(200)
            ->get();

        $histories = $this->historiesForSocialIds($socialIds);
        $rows = [];

        foreach ($posts as $post) {
            /** @var TrackedAccount|null $account */
            $account = $accounts->get($post->social_account_id);
            if ($account === null) {
                continue;
            }

            $label = $this->math->formatLabel($post);
            if ($format !== null && $format !== '' && strcasecmp($label, $format) !== 0) {
                continue;
            }

            $history = $histories->get((int) $post->social_account_id, collect());
            $pi = $this->math->performanceIndex($post, $history);
            $rows[] = $this->postRow($post, $account, $pi);
        }

        usort($rows, function (array $a, array $b): int {
            $aScore = is_numeric($a['times_usual'] ?? null) ? (float) $a['times_usual'] : -1.0;
            $bScore = is_numeric($b['times_usual'] ?? null) ? (float) $b['times_usual'] : -1.0;

            return $bScore <=> $aScore;
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topPosts(User $user, int $days = 30, int $limit = 10): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $limit = max(1, min(50, $limit));
        $since = CarbonImmutable::now(DashboardMath::TIMEZONE)->subDays($days);

        return $this->competitorPosts($user, [
            'since' => $since->toDateString(),
            'limit' => $limit,
            'sort' => 'times_usual',
        ]);
    }

    /**
     * @return array{slots: list<array{label: string, dow: int, hour: int, count: int}>, timezone: string}
     */
    public function postingTimes(User $user, int $days = 30): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $since = CarbonImmutable::now(DashboardMath::TIMEZONE)->subDays($days);
        $socialIds = $this->socialIdsForUser($user);

        $counts = [];
        if ($socialIds !== []) {
            $posts = Post::query()
                ->whereIn('social_account_id', $socialIds)
                ->whereNotNull('posted_at')
                ->where('posted_at', '>=', $since)
                ->get(['id', 'posted_at']);

            foreach ($posts as $post) {
                $bucket = $this->math->londonBucket($post->posted_at);
                if ($bucket === null) {
                    continue;
                }
                $dow = (int) ($bucket['dow'] ?? 0);
                $hour = (int) ($bucket['hour'] ?? -1);
                if ($hour < 0) {
                    continue;
                }
                $key = $dow.'|'.$hour;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $slots = collect($counts)
            ->map(function (int $count, string $key): array {
                [$dow, $hour] = array_map('intval', explode('|', $key));
                // londonBucket dow is ISO weekday minus 1 (Mon=0 … Sun=6).
                $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

                return [
                    'label' => ($dayNames[$dow] ?? 'Day').' '.sprintf('%02d:00', $hour),
                    'dow' => $dow,
                    'hour' => $hour,
                    'count' => $count,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->take(12)
            ->all();

        return [
            'timezone' => DashboardMath::TIMEZONE,
            'days' => $days,
            'slots' => $slots,
        ];
    }

    /**
     * @return array{days: int, own: array<string, int>, competitors: array<string, int>, total: array<string, int>}
     */
    public function formatMix(User $user, int $days = 30): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $since = CarbonImmutable::now(DashboardMath::TIMEZONE)->subDays($days);

        $accounts = $user->trackedAccounts()->get()->keyBy('social_account_id');
        $socialIds = $accounts->keys()->filter()->map(fn ($id): int => (int) $id)->values()->all();

        $own = [];
        $competitors = [];

        if ($socialIds !== []) {
            $posts = Post::query()
                ->whereIn('social_account_id', $socialIds)
                ->whereNotNull('posted_at')
                ->where('posted_at', '>=', $since)
                ->get(['id', 'social_account_id', 'type', 'raw_payload']);

            foreach ($posts as $post) {
                /** @var TrackedAccount|null $account */
                $account = $accounts->get($post->social_account_id);
                $label = $this->math->formatLabel($post);
                if ($account?->is_own_account) {
                    $own[$label] = ($own[$label] ?? 0) + 1;
                } else {
                    $competitors[$label] = ($competitors[$label] ?? 0) + 1;
                }
            }
        }

        $total = $own;
        foreach ($competitors as $label => $count) {
            $total[$label] = ($total[$label] ?? 0) + $count;
        }

        return [
            'days' => $days,
            'own' => $own,
            'competitors' => $competitors,
            'total' => $total,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function gaps(User $user): array
    {
        $now = CarbonImmutable::now(DashboardMath::TIMEZONE);

        return $user->trackedAccounts()
            ->orderByDesc('is_own_account')
            ->orderBy('handle')
            ->get()
            ->map(function (TrackedAccount $account) use ($now): array {
                $socialId = (int) ($account->social_account_id ?? 0);
                $lastPostedAt = null;
                if ($socialId > 0) {
                    $lastPostedAt = Post::query()
                        ->where('social_account_id', $socialId)
                        ->whereNotNull('posted_at')
                        ->orderByDesc('posted_at')
                        ->value('posted_at');
                }

                $days = null;
                if ($lastPostedAt !== null) {
                    $days = (int) CarbonImmutable::parse($lastPostedAt)
                        ->timezone(DashboardMath::TIMEZONE)
                        ->startOfDay()
                        ->diffInDays($now->startOfDay());
                }

                return [
                    'tracked_account_id' => (int) $account->id,
                    'handle' => (string) $account->handle,
                    'is_own_account' => (bool) $account->is_own_account,
                    'kind' => $account->kind instanceof \BackedEnum
                        ? $account->kind->value
                        : (string) $account->kind,
                    'last_posted_at' => $lastPostedAt !== null
                        ? CarbonImmutable::parse($lastPostedAt)->timezone(DashboardMath::TIMEZONE)->toIso8601String()
                        : null,
                    'days_since_last_post' => $days,
                    'quiet' => $days !== null && $days >= 14,
                    'sync_empty' => $socialId > 0 && $lastPostedAt === null,
                ];
            })
            ->sortByDesc(fn (array $row): int => (int) ($row['days_since_last_post'] ?? -1))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<string, mixed>
     */
    public function growthSeries(User $user, string $period = '30d', array $accountIds = []): array
    {
        $period = in_array($period, ['30d', '90d', 'all'], true) ? $period : '30d';

        return [
            'period' => $period,
            'metrics' => $this->growth->build($user, $period, $accountIds),
        ];
    }

    private function resolveAccount(User $user, ?string $handle): ?TrackedAccount
    {
        $query = $user->trackedAccounts()->orderByDesc('is_own_account')->orderBy('id');

        if ($handle !== null && trim($handle) !== '') {
            $normalised = $this->normaliseHandle($handle);

            return (clone $query)->whereRaw('LOWER(handle) = ?', [$normalised])->first();
        }

        return $query->where('is_own_account', true)->first()
            ?? $query->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function trackerRow(TrackedAccount $account): array
    {
        return [
            'id' => (int) $account->id,
            'platform' => $account->platform instanceof \BackedEnum
                ? $account->platform->value
                : (string) $account->platform,
            'handle' => (string) $account->handle,
            'display_name' => $account->display_name,
            'is_own_account' => (bool) $account->is_own_account,
            'kind' => $account->kind instanceof \BackedEnum
                ? $account->kind->value
                : (string) $account->kind,
            'followers' => $account->followers !== null ? (int) $account->followers : null,
            'url' => $account->url,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'last_sync_status' => $account->last_sync_status,
        ];
    }

    /**
     * @param  array{pi: float|null, prior_n: int, early: bool}  $pi
     * @return array<string, mixed>
     */
    private function postRow(Post $post, TrackedAccount $account, array $pi): array
    {
        $timesUsual = isset($pi['pi']) && is_numeric($pi['pi'])
            ? $this->math->round1((float) $pi['pi'])
            : null;

        return [
            'post_id' => (int) $post->id,
            'tracked_account_id' => (int) $account->id,
            'handle' => (string) $account->handle,
            'format' => $this->math->formatLabel($post),
            'url' => $post->url,
            'posted_at' => $post->posted_at?->timezone(DashboardMath::TIMEZONE)?->toIso8601String(),
            'caption' => mb_substr((string) $post->caption, 0, 280),
            'likes' => $this->math->likes($post),
            'comments' => $this->math->comments($post),
            'views' => $this->math->views($post),
            'times_usual' => $timesUsual,
            'times_usual_label' => $timesUsual !== null
                ? $timesUsual.' times their usual'
                : 'not enough history',
            'prior_n' => (int) ($pi['prior_n'] ?? 0),
            'likes_hidden' => $this->math->isHiddenLikes($post),
        ];
    }

    /**
     * @return array{change: int|null, from: int|null, to: int|null}
     */
    private function followerDelta(int $socialId, CarbonImmutable $fromDay, ?int $followersNow): array
    {
        if ($socialId < 1) {
            return ['change' => null, 'from' => null, 'to' => $followersNow];
        }

        $from = FollowerSnapshot::query()
            ->where('social_account_id', $socialId)
            ->whereDate('captured_on', '<=', $fromDay->toDateString())
            ->orderByDesc('captured_on')
            ->value('followers');

        $to = $followersNow;
        if ($to === null) {
            $to = FollowerSnapshot::query()
                ->where('social_account_id', $socialId)
                ->orderByDesc('captured_on')
                ->value('followers');
            $to = $to !== null ? (int) $to : null;
        }

        $fromInt = $from !== null ? (int) $from : null;

        return [
            'change' => ($fromInt !== null && $to !== null) ? ($to - $fromInt) : null,
            'from' => $fromInt,
            'to' => $to,
        ];
    }

    /**
     * @return list<int>
     */
    private function socialIdsForUser(User $user): array
    {
        return $user->trackedAccounts()
            ->whereNotNull('social_account_id')
            ->pluck('social_account_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Post>
     */
    private function historyForSocial(int $socialId): Collection
    {
        return Post::query()
            ->where('social_account_id', $socialId)
            ->whereNotNull('posted_at')
            ->orderByDesc('posted_at')
            ->get();
    }

    /**
     * @param  list<int>  $socialIds
     * @return Collection<int, Collection<int, Post>>
     */
    private function historiesForSocialIds(array $socialIds): Collection
    {
        if ($socialIds === []) {
            return collect();
        }

        return Post::query()
            ->whereIn('social_account_id', $socialIds)
            ->whereNotNull('posted_at')
            ->orderByDesc('posted_at')
            ->get()
            ->groupBy('social_account_id');
    }

    private function normaliseHandle(string $handle): string
    {
        return strtolower(ltrim(trim($handle), '@'));
    }
}
