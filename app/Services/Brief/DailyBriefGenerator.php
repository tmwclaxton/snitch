<?php

namespace App\Services\Brief;

use App\Mail\DailyBriefMail;
use App\Models\DailyBrief;
use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DailyBriefGenerator
{
    public function __construct(
        private DailyBriefFactsBuilder $facts,
        private DailyBriefValidator $validator,
        private NanoGptClient $client,
    ) {}

    public function briefDate(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now(DashboardMath::TIMEZONE);

        return $now->timezone(DashboardMath::TIMEZONE)->startOfDay();
    }

    public function briefForDate(User $user, CarbonImmutable $date): ?DailyBrief
    {
        return DailyBrief::query()
            ->where('user_id', $user->id)
            ->whereDate('brief_date', $date->toDateString())
            ->first();
    }

    /**
     * @return list<array{id: int, brief_date: string, generated_at: ?string}>
     */
    public function history(User $user, int $limit = 30): array
    {
        return DailyBrief::query()
            ->where('user_id', $user->id)
            ->where('status', 'ready')
            ->orderByDesc('brief_date')
            ->limit($limit)
            ->get()
            ->map(fn (DailyBrief $brief): array => [
                'id' => (int) $brief->id,
                'brief_date' => $brief->brief_date?->toDateString(),
                'generated_at' => $brief->generated_at?->timezone(DashboardMath::TIMEZONE)->toIso8601String(),
            ])
            ->all();
    }

    public function generate(User $user, ?CarbonImmutable $date = null, bool $force = false): DailyBrief
    {
        $date ??= $this->briefDate();
        $existing = $this->briefForDate($user, $date);

        if ($existing !== null && ! $force && $existing->status === 'ready') {
            return $existing;
        }

        $brief = $existing ?? new DailyBrief([
            'user_id' => $user->id,
            'brief_date' => $date->toDateString(),
        ]);

        $brief->fill([
            'status' => 'generating',
            'was_free' => true,
            'credits_charged_pence' => 0,
        ]);
        $brief->save();

        $facts = $this->facts->build($user, $date);
        $model = (string) config('snitch.daily_brief.model', config('snitch.brief.model'));
        $diagnostics = [];
        $llmOutput = null;
        $attempts = 0;
        $usedFallback = false;

        try {
            $first = $this->askModel($facts, $model, null);
            $attempts++;
            $diagnostics[] = ['attempt' => 1, 'errors' => $first['errors']];

            if ($first['ok']) {
                $llmOutput = $first['output'];
            } else {
                $second = $this->askModel($facts, $model, $first['errors']);
                $attempts++;
                $diagnostics[] = ['attempt' => 2, 'errors' => $second['errors']];

                if ($second['ok']) {
                    $llmOutput = $second['output'];
                } else {
                    $usedFallback = true;
                    $partial = $this->validator->dropInvalidActions($second['output'] ?? $first['output'] ?? [], $facts);
                    $partial = $this->validator->clearInvalidProse($partial, $facts);
                    $llmOutput = $this->mergeFallback($partial, $facts);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Daily brief LLM failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
            $usedFallback = true;
            $llmOutput = $this->mergeFallback([], $facts);
            $diagnostics[] = ['attempt' => $attempts, 'errors' => [$exception->getMessage()]];
        }

        $payload = $this->assemblePayload($facts, $llmOutput ?? [], $diagnostics, $usedFallback, $existing?->payload);

        $brief->fill([
            'status' => 'ready',
            'headline' => $payload['headline'],
            'payload' => $payload,
            'facts' => $facts,
            'model' => $usedFallback && $attempts === 0 ? null : $model,
            'llm_attempts' => $attempts,
            'was_free' => true,
            'credits_charged_pence' => 0,
            'generated_at' => now(),
        ]);
        $brief->save();

        $this->maybeMail($user, $brief);

        return $brief;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>|null  $violations
     * @return array{ok: bool, errors: list<string>, output: array<string, mixed>}
     */
    private function askModel(array $facts, string $model, ?array $violations): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($violations)],
            ['role' => 'user', 'content' => json_encode([
                'instruction' => 'Write today\'s executive summary from these facts only.',
                'facts' => $facts,
            ], JSON_UNESCAPED_SLASHES) ?: '{}'],
        ];

        $decoded = $this->client->chatJson($messages, $model, [
            'temperature' => 0.3,
            'max_tokens' => 1800,
        ]);

        if (! is_array($decoded)) {
            return ['ok' => false, 'errors' => ['model returned invalid JSON'], 'output' => []];
        }

        return $this->validator->validate($decoded, $facts);
    }

    /**
     * @param  list<string>|null  $violations
     */
    private function systemPrompt(?array $violations): string
    {
        $base = <<<'PROMPT'
You are a social media growth strategist for a small community / social-events Instagram account in London.
Write a daily executive summary as JSON only.
Schema:
{"headline":"...","actions":[{"title":"...","why":"...","how":"...","when":"HH:MM or null","format":"Reel|Carousel|Image|Story|Engage|null","hook":"literal first line or null","related_handles":["handle"],"related_post_ids":[123]}],"own_summary":"2-3 sentences","competitor_summary":"2-4 sentences","watch":["..."]}
Rules:
- 3 to 5 actions, each doable today in under 30 minutes.
- Be concrete: what to post, format, hook, time, which accounts to engage with and how.
- Instagram CTAs only: comment, save, share, DM, or link in bio.
- Never invent numbers. Only use numbers present in the facts.
- Only use handles present in facts.allowed_handles. Strip or keep @, but the handle must match.
- Only use related_post_ids from facts.allowed_post_ids.
- No em dashes or en dashes. Use a comma or hyphen.
- Do not truncate with ...
- hook is the literal first line, max 12 words. Never quote a scene description.
- When likes are hidden, say "likes hidden" or use views vs their usual. Never print null or 0x.
- Prefer "times their usual" over jargon.
- Cadence is in facts.cadence: posts_last_7d, days_since_last_post, distinct_days_posted_last_7, posted_every_day_last_7.
- Do not say an account posts daily or every day unless that handle has posted_every_day_last_7 true. Use the 7-day count and days since last post instead.
PROMPT;

        if ($violations === null || $violations === []) {
            return $base;
        }

        return $base."\nSECOND ATTEMPT. The previous draft was rejected for:\n- ".implode("\n- ", $violations)."\nFix every violation. Do not invent handles, post ids, or numbers.";
    }

    /**
     * @param  array<string, mixed>  $partial
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function mergeFallback(array $partial, array $facts): array
    {
        $fallback = $this->deterministicNarrative($facts);
        $actions = $partial['actions'] ?? [];

        if (! is_array($actions)) {
            $actions = [];
        }

        foreach ($fallback['actions'] as $action) {
            if (count($actions) >= 3) {
                break;
            }
            $actions[] = $action;
        }

        $max = max(3, min(5, (int) config('snitch.daily_brief.max_actions', 5)));
        $actions = array_slice(array_values($actions), 0, $max);

        return [
            'headline' => filled($partial['headline'] ?? null) ? (string) $partial['headline'] : $fallback['headline'],
            'actions' => $actions,
            'own_summary' => filled($partial['own_summary'] ?? null) ? (string) $partial['own_summary'] : $fallback['own_summary'],
            'competitor_summary' => filled($partial['competitor_summary'] ?? null) ? (string) $partial['competitor_summary'] : $fallback['competitor_summary'],
            'watch' => ($partial['watch'] ?? []) !== [] ? $partial['watch'] : $fallback['watch'],
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{headline: string, actions: list<array<string, mixed>>, own_summary: string, competitor_summary: string, watch: list<string>}
     */
    public function deterministicNarrative(array $facts): array
    {
        $own = $facts['own'] ?? null;
        $handle = is_array($own) ? (string) ($own['handle'] ?? 'your account') : 'your account';
        $followers = is_array($own) ? $own['followers_now'] : null;
        $posts7 = is_array($own) ? (int) ($own['posts_last_7d_count'] ?? 0) : 0;
        $daysSince = is_array($own) ? $own['days_since_last_post'] : null;
        $slot = $facts['best_times']['today_slot'] ?? null;
        $block = $facts['best_times']['weekday_evening_block'] ?? 'weekday evenings 19:00-21:00';
        $when = is_array($slot) ? sprintf('%02d:00', (int) $slot['hour']) : '20:00';
        $whenNote = is_array($slot) && ($slot['early_signal'] ?? false)
            ? $block.' (early signal)'
            : (is_array($slot) ? (string) $slot['label'] : $block);
        $hit = $facts['top_competitor_hit_24h'] ?? $facts['top_competitor_hit'] ?? null;
        $comp24 = (int) ($facts['format_mix']['competitor_posts_24h'] ?? 0);
        $comp7 = (int) ($facts['format_mix']['competitor_posts_7d'] ?? 0);
        $reels7 = (int) (($facts['format_mix']['competitors_7d']['Reel'] ?? 0));
        $change7 = is_array($own) ? ($own['followers_change_7d']['change'] ?? null) : null;

        $headline = 'Your next move is a Reel today.';
        if (is_numeric($change7) && (int) $change7 !== 0 && $reels7 > 0) {
            $headline = 'You are '.((int) $change7 > 0 ? 'up '.(int) $change7 : (string) (int) $change7).' followers this week but competitors posted '.$reels7.' Reels. Post one Reel today.';
        } elseif ($daysSince !== null && (int) $daysSince >= 3) {
            $headline = 'It has been '.(int) $daysSince.' days since you posted. Put out a Reel today at '.$when.'.';
        }

        $actions = [];
        $hitHandle = is_array($hit) ? (string) ($hit['handle'] ?? '') : '';
        $hitFormat = is_array($hit) ? (string) ($hit['format'] ?? 'Reel') : 'Reel';
        $hitPi = is_array($hit) ? ($hit['times_usual'] ?? $hit['views_vs_usual'] ?? null) : null;
        $hitLabel = is_numeric($hitPi) ? number_format((float) $hitPi, 1).' times their usual' : 'their best recent post';

        $actions[] = [
            'title' => 'Post a '.$hitFormat.' today at '.$when.'.',
            'why' => $hitHandle !== ''
                ? '@'.$hitHandle.' is getting '.$hitLabel.' with '.$hitFormat.'s.'
                : 'Competitors posted '.$reels7.' Reels in the last 7 days.',
            'how' => 'Keep it under 30 seconds. Open with a first-person line. Ask people to comment or DM.',
            'when' => $when,
            'format' => $hitFormat,
            'hook' => is_array($hit) ? ($hit['hook'] ?? null) : null,
            'related_handles' => $hitHandle !== '' ? [$hitHandle] : [],
            'related_post_ids' => is_array($hit) && isset($hit['post_id']) ? [(int) $hit['post_id']] : [],
        ];

        $quiet = [];
        foreach ($facts['competitors'] ?? [] as $row) {
            if (($row['quiet'] ?? false) || ($row['sync_empty'] ?? false)) {
                $quiet[] = $row;
            }
        }

        if ($quiet !== []) {
            $firstQuiet = $quiet[0];
            $qHandle = (string) ($firstQuiet['handle'] ?? '');
            $actions[] = [
                'title' => ($firstQuiet['sync_empty'] ?? false)
                    ? 'Check the @'.$qHandle.' handle, or swap this competitor.'
                    : 'Review @'.$qHandle.', quiet for '.((int) ($firstQuiet['days_since_last_post'] ?? 14)).' days.',
                'why' => ($firstQuiet['sync_empty'] ?? false)
                    ? '@'.$qHandle.' returned no posts. The handle may be wrong or private.'
                    : '@'.$qHandle.' has gone quiet. An active London club will teach you more.',
                'how' => 'Open the competitor page and confirm the account is public, or replace it.',
                'when' => null,
                'format' => 'Engage',
                'hook' => null,
                'related_handles' => $qHandle !== '' ? [$qHandle] : [],
                'related_post_ids' => [],
            ];
        }

        $ideas = $facts['unused_weekly_ideas'] ?? [];
        if ($ideas !== []) {
            $idea = $ideas[0];
            $actions[] = [
                'title' => 'Use unused Post this next idea '.(int) ($idea['position'] ?? 1).'.',
                'why' => 'This week\'s brief still has unused ideas you already paid time to write.',
                'how' => (string) ($idea['caption_angle'] ?? $idea['hook'] ?? 'Post the unused idea today.'),
                'when' => $when,
                'format' => $idea['format'] ?? 'Reel',
                'hook' => $idea['hook'] ?? null,
                'related_handles' => [$handle],
                'related_post_ids' => [],
            ];
        }

        if (count($actions) < 3) {
            $actions[] = [
                'title' => 'Spend 10 minutes commenting on two competitor posts from the last day.',
                'why' => 'Competitors published '.$comp24.' posts in the last 24 hours.',
                'how' => 'Leave a genuine comment, then share one useful post to your Story.',
                'when' => null,
                'format' => 'Engage',
                'hook' => null,
                'related_handles' => [],
                'related_post_ids' => [],
            ];
        }

        $ownSummary = $followers === null
            ? 'Follower history is still thin for @'.$handle.'.'
            : '@'.$handle.' has '.$followers.' followers and posted '.$posts7.' times in the last 7 days.';
        if ($daysSince !== null) {
            $ownSummary .= ' Last post was '.$daysSince.' days ago.';
        }

        $competitorSummary = 'Competitors published '.$comp7.' posts in the last 7 days, including '.$comp24.' in the last 24 hours.';
        if (is_array($hit) && $hitHandle !== '') {
            $competitorSummary .= ' Biggest recent hit: @'.$hitHandle.', '.$hitLabel.'.';
        }

        return [
            'headline' => $headline,
            'actions' => $actions,
            'own_summary' => $ownSummary,
            'competitor_summary' => $competitorSummary,
            'watch' => $this->deterministicWatch($facts, $whenNote),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private function deterministicWatch(array $facts, string $whenNote): array
    {
        $watch = [];

        foreach ($facts['competitors'] ?? [] as $row) {
            $handle = (string) ($row['handle'] ?? '');
            if ($handle === '') {
                continue;
            }

            if ($row['sync_empty'] ?? false) {
                $watch[] = '@'.$handle.' returned no posts. Check the handle is right and the account is public, or swap in a more active competitor.';
            } elseif ($row['quiet'] ?? false) {
                $days = (int) ($row['days_since_last_post'] ?? 14);
                $watch[] = '@'.$handle.' has been quiet for '.$days.' days. If it stays quiet, replace it with an active account.';
            }
        }

        if ($facts['ads_empty'] ?? true) {
            $watch[] = 'Snitch found no ads running for any of your tracked competitors.';
        }

        $own = $facts['own'] ?? null;
        if (is_array($own) && ! ($own['followers_change_1d']['available'] ?? false)) {
            $watch[] = 'Daily follower tracking starts today, so change since yesterday is not available yet.';
        }

        if ($facts['best_times']['thin'] ?? true) {
            $watch[] = 'Best posting time is an early signal ('.$whenNote.'). Treat timing as a guide.';
        }

        return $watch;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $llm
     * @param  list<array{attempt: int, errors: list<string>}>  $diagnostics
     * @param  array<string, mixed>|null  $previousPayload
     * @return array<string, mixed>
     */
    public function assemblePayload(array $facts, array $llm, array $diagnostics, bool $usedFallback, ?array $previousPayload = null): array
    {
        $own = is_array($facts['own'] ?? null) ? $facts['own'] : null;
        $change1d = is_array($own) ? ($own['followers_change_1d'] ?? null) : null;
        $hit24 = $facts['top_competitor_hit_24h'] ?? null;
        $actions = [];

        foreach (array_values($llm['actions'] ?? []) as $index => $action) {
            if (! is_array($action)) {
                continue;
            }

            $previous = $previousPayload['actions'][$index]['done_at'] ?? null;
            $actions[] = [
                'title' => $action['title'] ?? '',
                'why' => $action['why'] ?? '',
                'how' => $action['how'] ?? '',
                'when' => $action['when'] ?? null,
                'format' => $action['format'] ?? null,
                'hook' => $action['hook'] ?? null,
                'related_handles' => $action['related_handles'] ?? [],
                'related_post_ids' => $action['related_post_ids'] ?? [],
                'inspiration' => $this->inspirationLinks($facts, $action['related_post_ids'] ?? []),
                'done_at' => $previous,
            ];
        }

        $competitorMoves = [];
        foreach ($facts['competitors'] ?? [] as $row) {
            $competitorMoves[] = [
                'handle' => $row['handle'],
                'followers_now' => $row['followers_now'],
                'followers_change_7d' => $row['followers_change_7d'],
                'posts_last_24h' => $row['posts_last_24h'],
                'posts_last_7d_count' => $row['posts_last_7d_count'],
                'posts_last_7d_by_format' => $row['posts_last_7d_by_format'],
                'posts_last_7d' => $row['posts_last_7d'],
                'standout_winners' => $row['standout_winners'],
                'quiet' => $row['quiet'],
                'sync_empty' => $row['sync_empty'],
                'sync_status' => $row['sync_status'],
                'days_since_last_post' => $row['days_since_last_post'],
            ];
        }

        $ads = [];
        foreach ($facts['competitors'] ?? [] as $row) {
            foreach ($row['ads'] ?? [] as $ad) {
                $ads[] = [
                    'handle' => $row['handle'],
                    ...$ad,
                ];
            }
        }

        $watch = array_values(array_filter($llm['watch'] ?? [], fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
        if ($watch === []) {
            $watch = $this->deterministicWatch($facts, (string) ($facts['best_times']['weekday_evening_block'] ?? 'weekday evenings'));
        }

        return [
            'headline' => (string) ($llm['headline'] ?? 'Here is today\'s plan.'),
            'own_summary' => (string) ($llm['own_summary'] ?? ''),
            'competitor_summary' => (string) ($llm['competitor_summary'] ?? ''),
            'big_numbers' => [
                [
                    'label' => 'Followers',
                    'value' => is_array($own) && $own['followers_now'] !== null ? (string) $own['followers_now'] : 'Unknown',
                    'note' => is_array($own) ? ($own['followers_change_7d']['label'] ?? null) : null,
                ],
                [
                    'label' => 'Change since yesterday',
                    'value' => is_array($change1d) && ($change1d['available'] ?? false)
                        ? (string) ($change1d['change'] ?? 'Unknown')
                        : (is_array($change1d) ? (string) ($change1d['value'] ?? 'New') : 'New'),
                    'note' => is_array($change1d)
                        ? (string) ($change1d['label'] ?? $this->dailyTrackingStartedNote($facts))
                        : $this->dailyTrackingStartedNote($facts),
                ],
                [
                    'label' => 'Your posts this week',
                    'value' => (string) (is_array($own) ? ($own['posts_last_7d_count'] ?? 0) : 0),
                    'note' => is_array($own) && $own['last_posted_label']
                        ? 'Last post: '.$own['last_posted_label']
                        : null,
                ],
                [
                    'label' => 'Competitor posts in the last day',
                    'value' => (string) ($facts['format_mix']['competitor_posts_24h'] ?? 0),
                    'note' => $this->lastDayCompetitorNote($facts, $hit24),
                ],
            ],
            'actions' => $actions,
            'own_last_7_days' => is_array($own) ? ($own['posts_last_7d'] ?? []) : [],
            'own_yesterday' => is_array($own) ? ($own['posts_yesterday'] ?? []) : [],
            'unused_weekly_ideas' => $facts['unused_weekly_ideas'] ?? [],
            'competitor_moves' => $competitorMoves,
            'trends' => $this->trendLines($facts, (string) ($llm['competitor_summary'] ?? '')),
            'ads' => [
                'items' => $ads,
                'empty_label' => 'No ads found',
            ],
            'watch' => $watch,
            'data_freshness' => $this->freshness($facts),
            'validation' => [
                'used_fallback' => $usedFallback,
                'diagnostics' => $diagnostics,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<mixed>  $postIds
     * @return list<array{post_id: int, url: ?string, handle: ?string}>
     */
    private function inspirationLinks(array $facts, array $postIds): array
    {
        $wanted = array_map('intval', $postIds);
        $links = [];
        $walker = function (mixed $value) use (&$links, &$walker, $wanted): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['post_id']) && in_array((int) $value['post_id'], $wanted, true)) {
                $links[(int) $value['post_id']] = [
                    'post_id' => (int) $value['post_id'],
                    'url' => $value['url'] ?? null,
                    'handle' => $value['handle'] ?? null,
                ];
            }
            foreach ($value as $child) {
                $walker($child);
            }
        };
        $walker($facts);

        return array_values($links);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private function trendLines(array $facts, string $competitorSummary): array
    {
        $lines = [];
        $mix = $facts['format_mix']['competitors_7d'] ?? [];
        $total = max(1, (int) array_sum($mix));

        if (($mix['Reel'] ?? 0) > 0) {
            $lines[] = 'Reels were '.((int) $mix['Reel']).' of '.$total.' competitor posts this week.';
        }

        if ($competitorSummary !== '') {
            $lines[] = $competitorSummary;
        }

        return array_values(array_unique($lines));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private function freshness(array $facts): array
    {
        $rows = [];
        $accounts = [];

        if (is_array($facts['own'] ?? null)) {
            $accounts[] = $facts['own'];
        }

        foreach ($facts['competitors'] ?? [] as $row) {
            $accounts[] = $row;
        }

        foreach ($accounts as $row) {
            $rows[] = [
                'handle' => $row['handle'] ?? null,
                'last_synced_at' => $row['last_synced_at'] ?? null,
                'sync_status' => $row['sync_status'] ?? null,
                'sync_empty' => $row['sync_empty'] ?? false,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>|null  $hit24
     */
    private function lastDayCompetitorNote(array $facts, ?array $hit24): string
    {
        $count = (int) ($facts['format_mix']['competitor_posts_24h'] ?? 0);

        if ($count < 1 || $hit24 === null) {
            return 'none in the last day';
        }

        $handle = trim((string) ($hit24['handle'] ?? ''), '@');
        $label = trim((string) ($hit24['times_usual_label'] ?? ''));

        if ($handle === '') {
            return 'none in the last day';
        }

        return '@'.$handle.($label !== '' ? ', '.$label : '');
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function dailyTrackingStartedNote(array $facts): string
    {
        $date = filled($facts['brief_date'] ?? null)
            ? CarbonImmutable::parse((string) $facts['brief_date'], DashboardMath::TIMEZONE)
            : CarbonImmutable::now(DashboardMath::TIMEZONE);

        return sprintf(
            'Daily tracking started %s; first comparison tomorrow',
            $date->format('j M'),
        );
    }

    private function maybeMail(User $user, DailyBrief $brief): void
    {
        if (! $user->daily_brief_email) {
            return;
        }

        Mail::to($user->email)->send(new DailyBriefMail($brief));
    }
}
