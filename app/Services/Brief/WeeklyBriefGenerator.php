<?php

namespace App\Services\Brief;

use App\Enums\AnalysisStatus;
use App\Enums\BillingVendor;
use App\Enums\TrackedAccountKind;
use App\Jobs\GenerateWeeklyBriefJob;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use App\Services\Analysis\NanoGptClient;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WeeklyBriefGenerator
{
    public const CREDIT_PENCE = 5.0;

    public function __construct(
        private NanoGptClient $client,
        private DashboardMath $math,
        private VendorUsageCharger $charger,
        private UsageBillingService $billing,
    ) {}

    public function currentWeekStart(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now(DashboardMath::TIMEZONE);

        return $now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    public function briefForWeek(User $user, CarbonImmutable $weekStart): ?WeeklyBrief
    {
        return WeeklyBrief::query()
            ->where('user_id', $user->id)
            ->whereDate('week_start', $weekStart->toDateString())
            ->with('ideas')
            ->first();
    }

    /**
     * Current week's brief, or the most recent ready brief if this week is empty.
     */
    public function latestReadyBrief(User $user): ?WeeklyBrief
    {
        $current = $this->briefForWeek($user, $this->currentWeekStart());

        if ($current !== null && $current->status === 'ready') {
            return $current;
        }

        return WeeklyBrief::query()
            ->where('user_id', $user->id)
            ->where('status', 'ready')
            ->orderByDesc('week_start')
            ->with('ideas')
            ->first();
    }

    /**
     * @return array{ready: bool, competitors: int, analysed_posts_30d: int, winner_candidates: int}
     */
    public function dataSufficiency(User $user): array
    {
        $minCompetitors = max(1, (int) config('snitch.brief.min_competitors', 2));
        $minAnalysed = max(1, (int) config('snitch.brief.min_analysed_posts_30d', 10));
        $minWinners = max(1, (int) config('snitch.brief.min_winner_candidates', 3));

        $competitors = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('is_own_account', false)
            ->where('kind', TrackedAccountKind::Competitor)
            ->count();

        $socialIds = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('is_own_account', false)
            ->pluck('social_account_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $analysed = 0;

        if ($socialIds !== []) {
            $since = CarbonImmutable::now(DashboardMath::TIMEZONE)->subDays(30);
            $analysed = Post::query()
                ->whereIn('social_account_id', $socialIds)
                ->where('posted_at', '>=', $since)
                ->whereHas('analysis', fn ($q) => $q->where('status', AnalysisStatus::Completed))
                ->count();
        }

        $winners = count($this->topWinners($user, 30));

        return [
            'ready' => $competitors >= $minCompetitors
                && $analysed >= $minAnalysed
                && $winners >= $minWinners,
            'competitors' => $competitors,
            'analysed_posts_30d' => $analysed,
            'winner_candidates' => $winners,
        ];
    }

    public function hasEnoughData(User $user): bool
    {
        return $this->dataSufficiency($user)['ready'];
    }

    /**
     * Queue an automatic free brief when missing and data is sufficient.
     * Debounced; silent no-op when not ready or already present.
     */
    public function queueIfReady(User $user): bool
    {
        if (! BrandProfile::query()->where('user_id', $user->id)->exists()) {
            return false;
        }

        $weekStart = $this->currentWeekStart();

        if ($this->briefForWeek($user, $weekStart) !== null) {
            return false;
        }

        if (GenerateWeeklyBriefJob::isActiveFor($user->id)) {
            return false;
        }

        if (! $this->hasEnoughData($user)) {
            return false;
        }

        $debounceKey = 'weekly-brief-debounce:'.$user->id.':'.$weekStart->toDateString();
        $seconds = max(30, (int) config('snitch.brief.debounce_seconds', 300));

        if (! Cache::add($debounceKey, 1, now()->addSeconds($seconds))) {
            return false;
        }

        GenerateWeeklyBriefJob::queueFor($user->id, force: false, billable: false);

        return true;
    }

    /**
     * Generate this week's brief. Automatic runs are always free.
     * Billable regenerate is for admin force only.
     */
    public function generate(User $user, bool $force = false, bool $billable = false): WeeklyBrief
    {
        $weekStart = $this->currentWeekStart();
        $existing = $this->briefForWeek($user, $weekStart);

        if ($existing !== null && ! $force) {
            return $existing->load('ideas');
        }

        $chargePence = ($billable && $force) ? self::CREDIT_PENCE : 0.0;

        if ($chargePence > 0) {
            $this->charger->assertCanRun($user);
        }

        $brand = BrandProfile::query()->where('user_id', $user->id)->first();
        $winners = $this->topWinners($user, 30);
        $timing = $this->bestTimes($user);
        $ideas = $this->draftIdeas($user, $brand, $winners, $timing['slots']);

        return DB::transaction(function () use (
            $user,
            $weekStart,
            $existing,
            $chargePence,
            $timing,
            $ideas,
        ): WeeklyBrief {
            if ($existing !== null) {
                $existing->ideas()->delete();
                $brief = $existing;
            } else {
                $brief = new WeeklyBrief([
                    'user_id' => $user->id,
                    'week_start' => $weekStart->toDateString(),
                ]);
            }

            $brief->fill([
                'status' => 'ready',
                'best_times' => $timing['slots'],
                'heat_grid' => $timing['grid'],
                'thin_data' => $timing['thin'],
                'credits_charged_pence' => $chargePence,
                'was_free' => $chargePence <= 0,
                'generated_at' => now(),
            ])->save();

            foreach ($ideas as $index => $idea) {
                WeeklyBriefIdea::query()->create([
                    'weekly_brief_id' => $brief->id,
                    'position' => $index + 1,
                    'format' => $idea['format'],
                    'hook' => $idea['hook'],
                    'visual' => $idea['visual'] ?? null,
                    'caption_angle' => $idea['caption_angle'],
                    'cta' => $idea['cta'],
                    'hashtags' => $idea['hashtags'],
                    'recommended_day' => $idea['recommended_day'],
                    'recommended_hour' => $idea['recommended_hour'],
                    'inspired_by_post_ids' => $idea['inspired_by_post_ids'],
                    'why' => $idea['why'],
                ]);
            }

            if ($chargePence > 0) {
                $this->billing->charge(
                    $user,
                    'brief.weekly',
                    BillingVendor::Snitch,
                    null,
                    [
                        'week_start' => $weekStart->toDateString(),
                        'brief_id' => $brief->id,
                    ],
                    'brief.weekly:'.$user->id.':'.$weekStart->toDateString().':'.Str::uuid(),
                    $chargePence,
                );
            }

            return $brief->load('ideas');
        });
    }

    /**
     * Compact teaser for the dashboard panel (null when nothing to show).
     *
     * @return array{
     *     id: int,
     *     week_start: string|null,
     *     idea_count: int,
     *     hook: string|null,
     *     ideas: list<array{format: string, hook: string, slot: string, visual: ?string, caption_angle: ?string, cta: ?string}>
     * }|null
     */
    public function dashboardTeaser(User $user): ?array
    {
        $brief = $this->latestReadyBrief($user);

        if ($brief === null) {
            return null;
        }

        $ideas = $brief->ideas
            ->sortBy('position')
            ->take(3)
            ->values()
            ->map(function ($idea): array {
                $day = filled($idea->recommended_day) ? (string) $idea->recommended_day : 'Mon';
                $hour = is_int($idea->recommended_hour) ? $idea->recommended_hour : 9;

                return [
                    'format' => (string) ($idea->format ?: 'Reel'),
                    'hook' => (string) ($idea->hook ?: ''),
                    'slot' => sprintf('%s %02d:00', $day, $hour),
                    'visual' => filled($idea->visual) ? (string) $idea->visual : null,
                    'caption_angle' => filled($idea->caption_angle) ? (string) $idea->caption_angle : null,
                    'cta' => filled($idea->cta) ? (string) $idea->cta : null,
                ];
            })
            ->all();

        $bestTimes = collect(is_array($brief->best_times) ? $brief->best_times : [])
            ->take(3)
            ->values()
            ->map(function (mixed $slot): array {
                $row = is_array($slot) ? $slot : [];

                return [
                    'label' => (string) ($row['label'] ?? ''),
                    'score' => isset($row['score']) && is_numeric($row['score']) ? (float) $row['score'] : 0.0,
                ];
            })
            ->filter(fn (array $slot): bool => $slot['label'] !== '')
            ->values()
            ->all();

        return [
            'id' => (int) $brief->id,
            'week_start' => $brief->week_start?->toDateString(),
            'idea_count' => $brief->ideas->count(),
            'hook' => $ideas[0]['hook'] ?? null,
            'ideas' => $ideas,
            'best_times' => $bestTimes,
        ];
    }

    /**
     * Top winner candidates by X× usual, including the own account.
     *
     * @return list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string, is_own_account: bool}>
     */
    public function topWinners(User $user, int $days = 30): array
    {
        $accounts = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->get();

        $socialIds = $accounts->pluck('social_account_id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($socialIds === []) {
            return [];
        }

        $since = CarbonImmutable::now(DashboardMath::TIMEZONE)->subDays($days);
        $posts = Post::query()
            ->whereIn('social_account_id', $socialIds)
            ->where('posted_at', '>=', $since)
            ->with(['analysis', 'socialAccount'])
            ->orderByDesc('posted_at')
            ->limit(200)
            ->get();

        $bySocial = $posts->groupBy(fn (Post $post): int => (int) $post->social_account_id);
        $handleBySocial = $accounts->keyBy(fn (TrackedAccount $a) => (int) $a->social_account_id);

        $rows = [];

        foreach ($posts as $post) {
            $accountPosts = $bySocial->get((int) $post->social_account_id, collect());
            $pi = $this->math->performanceIndex($post, $accountPosts)['pi'];

            if ($pi === null || $pi < DashboardMath::WINNER_THRESHOLD) {
                continue;
            }

            $tracked = $handleBySocial->get((int) $post->social_account_id);
            $hashtags = $this->extractHashtags((string) ($post->caption ?? ''));

            $rows[] = [
                'post_id' => (int) $post->id,
                'handle' => (string) ($tracked?->handle ?? $post->socialAccount?->handle ?? 'unknown'),
                'pi' => round($pi, 2),
                'hook' => $post->analysis?->hook,
                'hashtags' => $hashtags,
                'format' => $this->math->formatLabel($post),
                'caption' => $post->caption,
                'is_own_account' => (bool) ($tracked?->is_own_account ?? false),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['pi'] <=> $a['pi']);

        // Feed the LLM the top 5-8 candidates (cap at 8).
        return array_slice($rows, 0, 8);
    }

    /**
     * @return array{slots: list<array{day: string, hour: int, label: string, score: float}>, grid: list<list<float|null>>, thin: bool}
     */
    public function bestTimes(User $user): array
    {
        $accounts = TrackedAccount::query()->where('user_id', $user->id)->get();
        $socialIds = $accounts->pluck('social_account_id')->filter()->map(fn ($id) => (int) $id)->all();

        $days = DashboardMath::DAYS;
        $grid = array_fill(0, 7, array_fill(0, 24, null));
        /** @var array<string, list<float>> $buckets */
        $buckets = [];

        if ($socialIds === []) {
            return ['slots' => [], 'grid' => $grid, 'thin' => true];
        }

        $posts = Post::query()
            ->whereIn('social_account_id', $socialIds)
            ->whereNotNull('posted_at')
            ->orderByDesc('posted_at')
            ->limit(300)
            ->get();

        $bySocial = $posts->groupBy(fn (Post $post): int => (int) $post->social_account_id);

        foreach ($posts as $post) {
            $pi = $this->math->performanceIndex($post, $bySocial->get((int) $post->social_account_id, collect()))['pi'];

            if ($pi === null || $post->posted_at === null) {
                continue;
            }

            $bucket = $this->math->londonBucket($post->posted_at);

            if ($bucket === null) {
                continue;
            }

            $dow = $bucket['dow'];
            $hour = $bucket['hour'];

            $key = $dow.'|'.$hour;
            $buckets[$key] ??= [];
            $buckets[$key][] = (float) $pi;
        }

        $slots = [];

        foreach ($buckets as $key => $values) {
            if (count($values) < 2) {
                continue;
            }

            [$dow, $hour] = array_map('intval', explode('|', $key));
            $median = $this->math->median($values) ?? 0.0;
            $grid[$dow][$hour] = round($median, 2);
            $slots[] = [
                'day' => $days[$dow] ?? 'Mon',
                'hour' => $hour,
                'label' => sprintf('%s %02d:00', $days[$dow] ?? 'Mon', $hour),
                'score' => round($median, 2),
            ];
        }

        usort($slots, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = array_slice($slots, 0, 3);
        $thin = count($slots) < 3;

        return ['slots' => $top, 'grid' => $grid, 'thin' => $thin];
    }

    /**
     * @param  list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string, is_own_account?: bool}>  $winners
     * @param  list<array{day: string, hour: int, label: string, score: float}>  $slots
     * @return list<array{format: string, hook: string, visual: ?string, caption_angle: string, cta: string, hashtags: list<string>, recommended_day: string, recommended_hour: int, inspired_by_post_ids: list<int>, why: string}>
     */
    private function draftIdeas(User $user, ?BrandProfile $brand, array $winners, array $slots): array
    {
        $candidates = array_slice($winners, 0, 8);
        $fallback = $this->deterministicIdeas($brand, $candidates, $slots);

        try {
            $model = (string) config('snitch.brief.model', config('snitch.winners.copy_model'));
            $payload = [
                'brand' => [
                    'name' => $brand?->name,
                    'description' => $brand?->description,
                ],
                'winners' => array_map(static fn (array $row): array => [
                    'post_id' => $row['post_id'],
                    'handle' => $row['handle'],
                    'pi' => $row['pi'],
                    'format' => $row['format'],
                    'hook' => $row['hook'],
                    'caption' => is_string($row['caption'] ?? null)
                        ? mb_substr((string) $row['caption'], 0, 180)
                        : null,
                    'hashtags' => $row['hashtags'],
                ], $candidates),
                'best_times' => $slots,
            ];

            $response = $this->client->chatJson(
                messages: [
                    [
                        'role' => 'system',
                        'content' => 'You write short Instagram post ideas for a brand. Return JSON only: {"ideas":[{"format":"Reel|Carousel|Image","hook":"...","visual":"optional shot direction","caption_angle":"...","cta":"...","hashtags":["#a","#b"],"inspired_by_post_ids":[123],"why":"..."}]}. Exactly 3 ideas. Rules: (1) hook is the literal first on-screen line the viewer reads, max 12 words, never a scene direction; (2) put camera/layout direction in visual only; (3) every idea must cite 1-2 inspired_by_post_ids from the winners list post_id values, and use different winners when possible (at most 2 ideas may share one post_id); (4) Instagram CTAs only: comment, save, share, DM, or link in bio - never swipe up; (5) hashtags 3-5 from winners when possible; (6) no em dashes.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ],
                ],
                model: $model,
                options: [
                    'temperature' => 0.4,
                    'max_tokens' => 1400,
                ],
            );

            if (is_array($response) && isset($response['ideas']) && is_array($response['ideas'])) {
                $parsed = $this->normalizeIdeas($response['ideas'], $candidates, $slots, $brand);

                if (count($parsed) === 3) {
                    return $parsed;
                }
            }
        } catch (\Throwable) {
            // Fall through to deterministic ideas.
        }

        return $fallback;
    }

    /**
     * @param  list<mixed>  $raw
     * @param  list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string, is_own_account?: bool}>  $winners
     * @param  list<array{day: string, hour: int, label: string, score: float}>  $slots
     * @return list<array{format: string, hook: string, visual: ?string, caption_angle: string, cta: string, hashtags: list<string>, recommended_day: string, recommended_hour: int, inspired_by_post_ids: list<int>, why: string}>
     */
    private function normalizeIdeas(array $raw, array $winners, array $slots, ?BrandProfile $brand): array
    {
        $formats = ['Reel', 'Carousel', 'Image'];
        $out = [];
        /** @var array<int, int> $sourceUses */
        $sourceUses = [];
        $validIds = collect($winners)->pluck('post_id')->map(fn ($id) => (int) $id)->all();

        foreach (array_slice($raw, 0, 3) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $format = (string) ($row['format'] ?? $formats[$index] ?? 'Reel');
            if (! in_array($format, $formats, true)) {
                $format = $formats[$index] ?? 'Reel';
            }

            $slot = $slots[$index] ?? $slots[0] ?? ['day' => 'Tue', 'hour' => 11];
            $sourceIds = collect($row['inspired_by_post_ids'] ?? [])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id): bool => in_array($id, $validIds, true))
                ->take(2)
                ->values()
                ->all();

            $sourceIds = $this->assignSources($sourceIds, $winners, $sourceUses, $index);

            $hashtags = collect($row['hashtags'] ?? [])
                ->filter(fn ($tag) => is_string($tag) && trim($tag) !== '')
                ->map(fn (string $tag): string => str_starts_with($tag, '#') ? $tag : '#'.$tag)
                ->take(5)
                ->values()
                ->all();

            if (count($hashtags) < 3) {
                $hashtags = $this->hashtagsFromWinners($winners, 5);
            }

            [$hook, $visual] = $this->sanitizeHookAndVisual(
                (string) ($row['hook'] ?? ''),
                is_string($row['visual'] ?? null) ? (string) $row['visual'] : null,
                $winners,
                $index,
                $brand,
            );

            $out[] = [
                'format' => $format,
                'hook' => $hook,
                'visual' => $visual,
                'caption_angle' => trim((string) ($row['caption_angle'] ?? 'Show the before/after and invite a reply')),
                'cta' => $this->sanitizeCta((string) ($row['cta'] ?? 'Comment your take')),
                'hashtags' => array_slice($hashtags, 0, 5),
                'recommended_day' => (string) $slot['day'],
                'recommended_hour' => (int) $slot['hour'],
                'inspired_by_post_ids' => $sourceIds,
                'why' => trim((string) ($row['why'] ?? $this->defaultWhy($winners, $index))),
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $preferred
     * @param  list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string, is_own_account?: bool}>  $winners
     * @param  array<int, int>  $sourceUses
     * @return list<int>
     */
    private function assignSources(array $preferred, array $winners, array &$sourceUses, int $index): array
    {
        $kept = [];

        foreach ($preferred as $id) {
            if (($sourceUses[$id] ?? 0) >= 2) {
                continue;
            }

            $kept[] = $id;
            $sourceUses[$id] = ($sourceUses[$id] ?? 0) + 1;
        }

        if ($kept !== []) {
            return array_slice($kept, 0, 2);
        }

        return $this->pickSourceIds($winners, $index, $sourceUses);
    }

    /**
     * @param  list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string, is_own_account?: bool}>  $winners
     * @param  array<int, int>  $sourceUses
     * @return list<int>
     */
    private function pickSourceIds(array $winners, int $index, array &$sourceUses): array
    {
        if ($winners === []) {
            return [];
        }

        // Prefer unused winners starting at this idea's rank, then once-used (hard cap: 2 ideas per source).
        foreach ([0, 1] as $maxUses) {
            for ($offset = 0; $offset < count($winners); $offset++) {
                $candidate = $winners[($index + $offset) % count($winners)] ?? null;

                if ($candidate === null) {
                    continue;
                }

                $id = (int) $candidate['post_id'];
                $uses = $sourceUses[$id] ?? 0;

                if ($uses >= 2 || $uses > $maxUses) {
                    continue;
                }

                $sourceUses[$id] = $uses + 1;

                return [$id];
            }
        }

        // All winners already used twice - still cite the next best match.
        $fallback = (int) $winners[$index % count($winners)]['post_id'];
        $sourceUses[$fallback] = ($sourceUses[$fallback] ?? 0) + 1;

        return [$fallback];
    }

    /**
     * @param  list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string, is_own_account?: bool}>  $winners
     * @return array{0: string, 1: ?string}
     */
    private function sanitizeHookAndVisual(
        string $rawHook,
        ?string $rawVisual,
        array $winners,
        int $index,
        ?BrandProfile $brand,
    ): array {
        $hook = trim($rawHook);
        $visual = filled($rawVisual) ? trim((string) $rawVisual) : null;

        $looksLikeDirection = $hook !== '' && (
            preg_match('/^(cover slide|show a|start with a|cut to|pan to|wide shot|close[- ]?up)\b/i', $hook) === 1
            || preg_match('/\b(megaphone icon|bold title|on screen|camera|b-roll|overlay|thumbnail)\b/i', $hook) === 1
            || preg_match('/^(cover|slide|shot)\s*:/i', $hook) === 1
        );

        if ($looksLikeDirection) {
            $visual = $visual ?? $hook;

            if (preg_match('/["“]([^"”]{3,80})["”]/u', $hook, $match) === 1) {
                $hook = trim($match[1]);
            } elseif (preg_match('/:\s*(.+)$/u', $hook, $match) === 1) {
                $hook = trim($match[1]);
                $hook = preg_replace('/\b(with a|showing a|featuring a)\b.+$/iu', '', $hook) ?? $hook;
            } else {
                $winnerHook = $winners[$index]['hook'] ?? $winners[0]['hook'] ?? null;
                $hook = filled($winnerHook)
                    ? (string) $winnerHook
                    : 'Proof beats promises';
            }
        }

        if ($hook === '') {
            $brandName = trim((string) ($brand?->name ?? 'your brand'));
            $hook = "How {$brandName} gets results";
        }

        $words = preg_split('/\s+/u', $hook, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) > 12) {
            $hook = implode(' ', array_slice($words, 0, 12));
        }

        $hook = trim((string) preg_replace('/\s+/u', ' ', $hook));
        $visual = filled($visual) ? trim((string) $visual) : null;

        if ($visual !== null && mb_strtolower($visual) === mb_strtolower($hook)) {
            $visual = null;
        }

        return [$hook, $visual];
    }

    private function sanitizeCta(string $cta): string
    {
        $cta = trim($cta);
        $lower = mb_strtolower($cta);

        $banned = ['swipe up', 'swipe-up', 'link in story', 'tap the sticker', 'add to cart'];

        foreach ($banned as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'Comment your take';
            }
        }

        $allowed = ['comment', 'save', 'share', 'dm', 'link in bio', 'reply'];
        $ok = false;

        foreach ($allowed as $phrase) {
            if (str_contains($lower, $phrase)) {
                $ok = true;
                break;
            }
        }

        if (! $ok || $cta === '') {
            return 'Comment your take';
        }

        return $cta;
    }

    /**
     * @param  list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string, is_own_account?: bool}>  $winners
     * @param  list<array{day: string, hour: int, label: string, score: float}>  $slots
     * @return list<array{format: string, hook: string, visual: ?string, caption_angle: string, cta: string, hashtags: list<string>, recommended_day: string, recommended_hour: int, inspired_by_post_ids: list<int>, why: string}>
     */
    private function deterministicIdeas(?BrandProfile $brand, array $winners, array $slots): array
    {
        $formats = ['Reel', 'Carousel', 'Image'];
        $brandName = trim((string) ($brand?->name ?? 'your brand'));
        $hashtags = $this->hashtagsFromWinners($winners, 5);
        $ideas = [];
        /** @var array<int, int> $sourceUses */
        $sourceUses = [];

        for ($i = 0; $i < 3; $i++) {
            $sourceIds = $this->pickSourceIds($winners, $i, $sourceUses);
            $winner = null;

            if ($sourceIds !== []) {
                $winner = collect($winners)->firstWhere('post_id', $sourceIds[0])
                    ?? ($winners[$i] ?? $winners[0] ?? null);
            } else {
                $winner = $winners[$i] ?? $winners[0] ?? null;
            }

            $slot = $slots[$i] ?? $slots[0] ?? ['day' => 'Wed', 'hour' => 12];
            [$hook, $visual] = $this->sanitizeHookAndVisual(
                filled($winner['hook'] ?? null)
                    ? (string) $winner['hook']
                    : "How {$brandName} solves this",
                null,
                $winners,
                $i,
                $brand,
            );
            $pi = $winner['pi'] ?? null;
            $handle = $winner['handle'] ?? 'rivals';

            $ideas[] = [
                'format' => $formats[$i],
                'hook' => $hook,
                'visual' => $visual,
                'caption_angle' => "Translate @{$handle}'s angle into {$brandName}'s voice, then close with a clear next step.",
                'cta' => 'Comment your take',
                'hashtags' => $hashtags,
                'recommended_day' => (string) $slot['day'],
                'recommended_hour' => (int) $slot['hour'],
                'inspired_by_post_ids' => $sourceIds !== [] ? $sourceIds : ($winner ? [(int) $winner['post_id']] : []),
                'why' => $pi !== null
                    ? sprintf('@%s hit %.1f× usual - remix that proof pattern this week.', $handle, $pi)
                    : 'Grounded in recent competitor winners and your brand profile.',
            ];
        }

        return $ideas;
    }

    /**
     * @param  list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string}>  $winners
     * @return list<string>
     */
    private function hashtagsFromWinners(array $winners, int $limit): array
    {
        $tags = collect($winners)
            ->flatMap(fn (array $row) => $row['hashtags'] ?? [])
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();

        if (count($tags) >= 3) {
            return $tags;
        }

        return array_values(array_unique([...$tags, '#marketing', '#content', '#growth']));
    }

    /**
     * @return list<string>
     */
    private function extractHashtags(string $caption): array
    {
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $tag): string => mb_strtolower($tag))
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  list<array{post_id: int, handle: string, pi: float, hook: ?string, hashtags: list<string>, format: string, caption: ?string}>  $winners
     */
    private function defaultWhy(array $winners, int $index): string
    {
        $winner = $winners[$index] ?? $winners[0] ?? null;

        if ($winner === null) {
            return 'Grounded in your brand profile and recent competitive patterns.';
        }

        return sprintf(
            '@%s cleared %.1f× usual - borrow the structure, keep your voice.',
            $winner['handle'],
            $winner['pi'],
        );
    }
}
