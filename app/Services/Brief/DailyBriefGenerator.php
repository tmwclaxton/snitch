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
        private DailyBriefCopySanitizer $copySanitizer,
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

        $kept = $this->snapshotExistingLlmBrief($existing);

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

        if ($usedFallback && $kept !== null) {
            Log::warning('Daily brief fallback skipped; keeping existing LLM brief', [
                'user_id' => $user->id,
                'brief_id' => $brief->id,
                'brief_date' => $date->toDateString(),
                'diagnostics' => $diagnostics,
            ]);

            $brief->fill([
                'status' => 'ready',
                'headline' => $kept['headline'],
                'payload' => $kept['payload'],
                'facts' => $kept['facts'],
                'model' => $kept['model'],
                'llm_attempts' => $kept['llm_attempts'],
                'was_free' => true,
                'credits_charged_pence' => 0,
                'generated_at' => $kept['generated_at'],
            ]);
            $brief->save();
            $this->copySanitizer->sanitizeStored($brief);
            $brief->refresh();

            return $brief;
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
            'tries' => (int) config('snitch.daily_brief.llm_tries', 4),
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
- On Stories, call Instagram's sticker the "link sticker" (never "link in bio sticker"). "Link in bio" is only for posts and Reels.
- Never invent numbers. Only use numbers present in the facts.
- Only use handles present in facts.allowed_handles. Strip or keep @, but the handle must match.
- Only use related_post_ids from facts.allowed_post_ids.
- Never mention internal post ids in any text field. Do not write "post 218", "post #218", "post_id 218", or "(post_id 218)". Refer to the post by a short description such as "their Living Room Listens Reel". Keep the database id only in related_post_ids.
- Never borrow named people from competitor captions. If a competitor spotlight names someone (for example Jessie), do not use that name in hooks, ideas, or actions. Say "a member's story" instead. Names in facts.borrowed_competitor_names are forbidden.
- Only call a post a standout, hit, top, or outperformed when its times_usual or views_vs_usual is above 1.2. "Best" is only for that handle's genuine in-window best or a post above 1.2.
- No em dashes or en dashes. Use a comma or hyphen.
- Do not truncate with ...
- hook is the literal first line, max 12 words. Never quote a scene description.
- When likes are hidden, say "likes hidden" or "N times their usual" from views_vs_usual. Never print null, 0x, or "N views vs usual".
- Prefer "times their usual" over jargon. Never mix views and likes labels.
- Zero and one are valid counts when they appear in the facts (for example "0 posts in the last 7 days").
- Do not use accounts with no posts (sync_empty, or null last_posted_at) for longest-gap claims. facts.cadence_gaps already excludes them.
- Cadence is in facts.cadence: posts_last_7d, days_since_last_post, distinct_days_posted_last_7, posted_every_day_last_7, posted_almost_daily_last_7.
- Brand and own-account handles in facts.brand.own_handles and facts.own.handle are always allowed, even if they also appear in facts.allowed_handles.
- Do not say an account posts daily or every day unless that handle has posted_every_day_last_7 true.
- Do not say almost daily, nearly daily, almost every day, or posts most days unless posted_almost_daily_last_7 is true (6 or 7 distinct days with a post in the last 7).
- Use the 7-day count and days since last post instead of a cadence adjective.
- When describing follower change, use facts.own.followers_change_7d.label (for example +4 since 27 September). Do not say gained or lost in the last 7 days or this week unless from_date is exactly 7 days before brief_date.
- standout_winners and top_competitor_hit may look back 30 days. For any "best post this week" or "last 7 days" claim, only use posts_last_7d, best_post_7d, or top_competitor_hit_7d.
- Do not say the brand has the longest posting gap unless facts.cadence_gaps.own_is_longest is true. If you mention a longest gap, use cadence_gaps.longest_handle.
- If sync_failed is true, say the refresh failed. Do not say the account is quiet or has not posted recently.
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
        $hit = $this->fallbackInspirationPost($facts);
        $inWindow = $facts['top_competitor_hit_24h'] ?? $facts['top_competitor_hit_7d'] ?? null;
        $comp24 = (int) ($facts['format_mix']['competitor_posts_24h'] ?? 0);
        $comp7 = (int) ($facts['format_mix']['competitor_posts_7d'] ?? 0);
        $reels7 = (int) (($facts['format_mix']['competitors_7d']['Reel'] ?? 0));
        $change7 = is_array($own) ? ($own['followers_change_7d']['change'] ?? null) : null;
        $changeLabel = is_array($own) ? (string) ($own['followers_change_7d']['label'] ?? '') : '';

        $headline = 'Your next move is a Reel today.';
        if (is_numeric($change7) && (int) $change7 !== 0 && $reels7 > 0) {
            $changeNote = $changeLabel !== ''
                ? $changeLabel
                : ((int) $change7 > 0 ? 'up '.(int) $change7 : (string) (int) $change7).' followers';
            $headline = 'You are '.$changeNote.' but competitors posted '.$this->pluralise($reels7, 'Reel').'. Post one Reel today.';
        } elseif ($daysSince !== null && (int) $daysSince >= 3) {
            $headline = 'It has been '.$this->pluralise((int) $daysSince, 'day').' since you posted. Put out a Reel today at '.$when.'.';
        }

        $actions = [];
        $hitHandle = is_array($hit) ? (string) ($hit['handle'] ?? '') : '';
        $hitFormat = is_array($hit)
            ? (string) ($hit['format'] ?? 'Reel')
            : (is_array($inWindow) ? (string) ($inWindow['format'] ?? 'Reel') : 'Reel');
        $hitFormat = $hitFormat !== '' ? $hitFormat : 'Reel';
        $hitLabel = 'their best recent post';
        if (is_array($hit) && filled($hit['times_usual_label'] ?? null)) {
            $hitLabel = (string) $hit['times_usual_label'];
        } else {
            $hitPi = is_array($hit) ? ($hit['times_usual'] ?? $hit['views_vs_usual'] ?? null) : null;
            if (is_numeric($hitPi)) {
                $hitLabel = number_format((float) $hitPi, 1).' times their usual';
            }
        }

        $actions[] = [
            'title' => 'Post a '.$hitFormat.' today at '.$when.'.',
            'why' => $hitHandle !== ''
                ? '@'.$hitHandle.' is getting '.$hitLabel.' with '.$hitFormat.'s.'
                : 'Competitors posted '.$this->pluralise($reels7, 'Reel').' in the last 7 days.',
            'how' => 'Keep it under 30 seconds. Open with a first-person line. Ask people to comment or DM.',
            'when' => $when,
            'format' => $hitFormat,
            'hook' => is_array($hit) ? ($hit['hook'] ?? null) : null,
            'related_handles' => $hitHandle !== '' ? [$hitHandle] : [],
            'related_post_ids' => is_array($hit) && isset($hit['post_id']) ? [(int) $hit['post_id']] : [],
        ];

        $failed = [];
        $quiet = [];
        foreach ($facts['competitors'] ?? [] as $row) {
            if ($row['sync_failed'] ?? false) {
                $failed[] = $row;
            } elseif (($row['quiet'] ?? false) || ($row['sync_empty'] ?? false)) {
                $quiet[] = $row;
            }
        }

        if ($failed !== []) {
            $firstFailed = $failed[0];
            $fHandle = (string) ($firstFailed['handle'] ?? '');
            $actions[] = [
                'title' => 'Check why @'.$fHandle.' failed to refresh.',
                'why' => 'The last sync for @'.$fHandle.' failed, so we cannot say they have gone quiet.',
                'how' => 'Open the competitor page and run sync again, or replace the handle if it is wrong.',
                'when' => null,
                'format' => 'Engage',
                'hook' => null,
                'related_handles' => $fHandle !== '' ? [$fHandle] : [],
                'related_post_ids' => [],
            ];
        } elseif ($quiet !== []) {
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

        if (count($actions) < 3) {
            $actions[] = [
                'title' => 'Reply to two comments on your last post.',
                'why' => '@'.$handle.' posted '.$this->pluralise($posts7, 'time').' in the last 7 days.',
                'how' => 'Reply in the comments, then share the post to your Story.',
                'when' => null,
                'format' => 'Engage',
                'hook' => null,
                'related_handles' => $handle !== 'your account' ? [$handle] : [],
                'related_post_ids' => [],
            ];
        }

        $ownSummary = $followers === null
            ? 'Follower history is still thin for @'.$handle.'.'
            : '@'.$handle.' has '.$followers.' followers and posted '.$this->pluralise($posts7, 'time').' in the last 7 days.';
        if ($changeLabel !== '' && is_numeric($change7)) {
            $ownSummary .= ' Follower change: '.$changeLabel.'.';
        }
        if ($daysSince !== null) {
            $ownSummary .= ' Last post was '.$this->pluralise((int) $daysSince, 'day').' ago.';
        }

        $competitorSummary = 'Competitors published '.$this->pluralise($comp7, 'post').' in the last 7 days, including '.$this->pluralise($comp24, 'post').' in the last 24 hours.';
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

            if ($row['sync_failed'] ?? false) {
                $watch[] = '@'.$handle.' could not be refreshed. Do not treat this as silence until a sync succeeds.';
            } elseif ($row['sync_empty'] ?? false) {
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
            if (! is_array($row)) {
                continue;
            }

            $competitorMoves[] = [
                'handle' => $row['handle'] ?? null,
                'followers_now' => $row['followers_now'] ?? null,
                'followers_change_7d' => $row['followers_change_7d'] ?? null,
                'posts_last_24h' => $row['posts_last_24h'] ?? [],
                'posts_last_7d_count' => $row['posts_last_7d_count'] ?? 0,
                'posts_last_7d_by_format' => $row['posts_last_7d_by_format'] ?? [],
                'posts_last_7d' => $row['posts_last_7d'] ?? [],
                'standout_winners' => $row['standout_winners'] ?? [],
                'quiet' => $row['quiet'] ?? false,
                'sync_empty' => $row['sync_empty'] ?? false,
                'sync_failed' => $row['sync_failed'] ?? false,
                'sync_status' => $row['sync_status'] ?? null,
                'days_since_last_post' => $row['days_since_last_post'] ?? null,
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
                    'note' => is_array($own) && filled($own['last_posted_label'] ?? null)
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
            'unused_weekly_ideas' => $this->copySanitizer->sanitizeUnusedWeeklyIdeas(
                is_array($facts['unused_weekly_ideas'] ?? null) ? $facts['unused_weekly_ideas'] : [],
                $facts,
            ),
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
                'sync_failed' => $row['sync_failed'] ?? false,
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

        if ($count < 1) {
            return 'none in the last day';
        }

        $parts = [];

        foreach ($facts['competitors'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $handle = trim((string) ($row['handle'] ?? ''), '@');

            if ($handle === '') {
                continue;
            }

            foreach ($row['posts_last_24h'] ?? [] as $post) {
                if (! is_array($post)) {
                    continue;
                }

                $format = trim((string) ($post['format'] ?? 'post'));
                $format = $format !== '' ? $format : 'post';
                $timesUsual = $post['times_usual'] ?? null;
                $viewsVsUsual = $post['views_vs_usual'] ?? null;

                if (is_numeric($timesUsual)) {
                    $parts[] = '@'.$handle.' '.$format.' '.number_format((float) $timesUsual, 1).'x';
                } elseif (is_numeric($viewsVsUsual)) {
                    $parts[] = '@'.$handle.' '.$format.' '.number_format((float) $viewsVsUsual, 1).'x';
                } else {
                    $parts[] = '@'.$handle.' '.$format;
                }
            }
        }

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        $handle = trim((string) ($hit24['handle'] ?? ''), '@');
        $label = trim((string) ($hit24['times_usual_label'] ?? ''));

        if ($handle === '') {
            return $count.' in the last day';
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

    /**
     * @return array{headline: string, payload: array<string, mixed>, facts: array<string, mixed>, model: string|null, llm_attempts: int, generated_at: mixed}|null
     */
    private function snapshotExistingLlmBrief(?DailyBrief $brief): ?array
    {
        if ($brief === null || $brief->status !== 'ready') {
            return null;
        }

        $payload = is_array($brief->payload) ? $brief->payload : [];

        if (($payload['validation']['used_fallback'] ?? false) === true) {
            return null;
        }

        if ((int) $brief->llm_attempts < 1 && ! filled($brief->model)) {
            return null;
        }

        if (! filled($brief->headline) || $payload === []) {
            return null;
        }

        return [
            'headline' => (string) $brief->headline,
            'payload' => $payload,
            'facts' => is_array($brief->facts) ? $brief->facts : [],
            'model' => $brief->model,
            'llm_attempts' => (int) $brief->llm_attempts,
            'generated_at' => $brief->generated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>|null
     */
    private function fallbackInspirationPost(array $facts): ?array
    {
        foreach ([
            $facts['top_competitor_hit_24h'] ?? null,
            $facts['top_competitor_hit_7d'] ?? null,
            $facts['top_competitor_hit'] ?? null,
        ] as $row) {
            if ($this->beatUsual(is_array($row) ? $row : null)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function beatUsual(?array $row): bool
    {
        if ($row === null) {
            return false;
        }

        $score = $row['times_usual'] ?? $row['views_vs_usual'] ?? null;

        return is_numeric($score) && (float) $score > 1.0;
    }

    private function pluralise(int $count, string $singular, ?string $plural = null): string
    {
        $plural ??= $singular.'s';

        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function maybeMail(User $user, DailyBrief $brief): void
    {
        if (! $user->daily_brief_email) {
            return;
        }

        Mail::to($user->email)->send(new DailyBriefMail($brief));
    }
}
