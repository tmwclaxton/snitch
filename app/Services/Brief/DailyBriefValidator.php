<?php

namespace App\Services\Brief;

use Carbon\CarbonImmutable;

class DailyBriefValidator
{
    public const STANDOUT_THRESHOLD = 1.2;

    /**
     * Match internal post id phrasing, including underscore/hyphen/colon and brackets.
     */
    private const INTERNAL_POST_ID_PATTERN = '/\(\s*(?:post[\s_\-]*id|post|id)\s*[:#]?\s*(\d+)\s*\)|\b(?:post[\s_\-]*id|post)\s*[:#]?\s*(\d+)\b/i';

    public function __construct(
        private BorrowedCompetitorNameSanitizer $borrowedNames,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array{ok: bool, errors: list<string>, output: array<string, mixed>}
     */
    public function validate(array $output, array $facts): array
    {
        $output = $this->replaceDashes($output);
        $output = $this->rewriteInternalPostIds($output, $facts);
        $output = $this->rewriteBorrowedCompetitorNames($output, $facts);
        $errors = [];

        $headline = trim((string) ($output['headline'] ?? ''));
        $actions = $output['actions'] ?? null;
        $ownSummary = trim((string) ($output['own_summary'] ?? ''));
        $competitorSummary = trim((string) ($output['competitor_summary'] ?? ''));
        $watch = $output['watch'] ?? [];

        if ($headline === '') {
            $errors[] = 'headline is empty';
        }

        if (! is_array($actions)) {
            $errors[] = 'actions must be an array';
            $actions = [];
        }

        $count = count($actions);

        if ($count < 3 || $count > 5) {
            $errors[] = 'need 3 to 5 actions, got '.$count;
        }

        $handles = $this->allowedHandles($facts);
        $postIds = $this->allowedPostIds($facts);
        $allowedNumbers = $this->allowedNumbers($facts);

        $texts = [$headline, $ownSummary, $competitorSummary];

        foreach ($watch as $item) {
            if (is_string($item)) {
                $texts[] = $item;
            }
        }

        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                $errors[] = 'action '.($index + 1).' is not an object';

                continue;
            }

            $title = trim((string) ($action['title'] ?? ''));
            $why = trim((string) ($action['why'] ?? ''));
            $how = trim((string) ($action['how'] ?? ''));
            $hook = trim((string) ($action['hook'] ?? ''));

            if ($title === '') {
                $errors[] = 'action '.($index + 1).' is missing a title';
            }

            if ($this->containsEllipsis($title.$why.$how.$hook)) {
                $errors[] = 'action '.($index + 1).' truncates with ...';
            }

            if ($hook !== '' && str_word_count($hook) > 12) {
                $errors[] = 'action '.($index + 1).' hook is longer than 12 words';
            }

            $relatedHandles = $action['related_handles'] ?? [];
            if (! is_array($relatedHandles)) {
                $errors[] = 'action '.($index + 1).' related_handles must be an array';
                $relatedHandles = [];
            }

            foreach ($relatedHandles as $handle) {
                $normalised = $this->normaliseHandle((string) $handle);
                if ($normalised !== '' && ! in_array($normalised, $handles, true)) {
                    $errors[] = 'unknown handle @'.$normalised;
                }
            }

            $relatedPosts = $action['related_post_ids'] ?? [];
            if (! is_array($relatedPosts)) {
                $errors[] = 'action '.($index + 1).' related_post_ids must be an array';
                $relatedPosts = [];
            }

            foreach ($relatedPosts as $postId) {
                if (! in_array((int) $postId, $postIds, true)) {
                    $errors[] = 'unknown post id '.$postId;
                }
            }

            $texts[] = $title;
            $texts[] = $why;
            $texts[] = $how;
            $texts[] = $hook;
            $texts[] = (string) ($action['when'] ?? '');
        }

        if ($this->containsEllipsis(implode(' ', $texts))) {
            $errors[] = 'text truncates with ...';
        }

        foreach ($this->handlesInText(implode("\n", $texts)) as $handle) {
            if (! in_array($handle, $handles, true)) {
                $errors[] = 'unknown handle @'.$handle;
            }
        }

        foreach ($this->numbersInText(implode("\n", $texts)) as $number) {
            if ($this->isAllowedSmallInt($number) || $this->isClock($number)) {
                continue;
            }

            if (! $this->numberIsKnown($number, $allowedNumbers)) {
                $errors[] = 'invented number '.$number;
            }
        }

        foreach ($texts as $text) {
            foreach ($this->unsupportedDailyClaims($text, $facts) as $error) {
                $errors[] = $error;
            }

            $windowError = $this->unsupportedFollowerWindow($text, $facts);
            if ($windowError !== null) {
                $errors[] = $windowError;
            }

            $weekBestError = $this->unsupportedWeekBestClaim($text, $facts);
            if ($weekBestError !== null) {
                $errors[] = $weekBestError;
            }

            $gapError = $this->unsupportedLongestGapClaim($text, $facts);
            if ($gapError !== null) {
                $errors[] = $gapError;
            }

            $standoutError = $this->unsupportedStandoutClaim($text, $facts);
            if ($standoutError !== null) {
                $errors[] = $standoutError;
            }

            $borrowed = $this->borrowedCompetitorNames($facts);
            $borrowedName = $this->borrowedNames->firstBorrowedNameIn($text, $borrowed);
            if ($borrowedName !== null) {
                $errors[] = 'borrowed competitor person name ('.$borrowedName.')';
            }

            foreach ($this->internalPostIdMentions($text, $facts) as $mention) {
                $errors[] = 'internal post id in copy ('.$mention.')';
            }
        }

        $output['headline'] = $headline;
        $output['actions'] = array_values(array_filter($actions, fn (mixed $action): bool => is_array($action)));
        $output['own_summary'] = $ownSummary;
        $output['competitor_summary'] = $competitorSummary;
        $output['watch'] = is_array($watch) ? array_values(array_filter($watch, fn (mixed $item): bool => is_string($item) && trim($item) !== '')) : [];

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'output' => $output,
        ];
    }

    /**
     * Drop actions that cite unknown handles, post ids, or invented numbers.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function dropInvalidActions(array $output, array $facts): array
    {
        $kept = [];

        foreach ($output['actions'] ?? [] as $action) {
            if (! is_array($action)) {
                continue;
            }

            $probe = $output;
            $probe['actions'] = [$action];
            $probe['headline'] = $output['headline'] ?? '';
            $probe['own_summary'] = '';
            $probe['competitor_summary'] = '';
            $probe['watch'] = [];
            $result = $this->validate($probe, $facts);

            $actionOnlyErrors = array_values(array_filter(
                $result['errors'],
                fn (string $error): bool => ! str_contains($error, 'need 3 to 5')
                    && $error !== 'headline is empty',
            ));

            if ($actionOnlyErrors === []) {
                $rewritten = $result['output']['actions'][0] ?? $action;
                $kept[] = is_array($rewritten) ? $rewritten : $action;
            }
        }

        $output['actions'] = $kept;

        return $output;
    }

    /**
     * Blank headline, summaries, and watch lines that still fail validation
     * so deterministic fallback can replace them.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function clearInvalidProse(array $output, array $facts): array
    {
        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            $probe = $this->proseProbe($field, $output[$field] ?? '', []);
            if (! $this->validate($probe, $facts)['ok']) {
                $output[$field] = '';
            }
        }

        $watch = [];

        foreach ($output['watch'] ?? [] as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            $probe = $this->proseProbe('watch', '', [$item]);
            if ($this->validate($probe, $facts)['ok']) {
                $watch[] = $item;
            }
        }

        $output['watch'] = $watch;

        return $output;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function replaceDashes(array $value): array
    {
        array_walk_recursive($value, function (mixed &$item): void {
            if (is_string($item)) {
                $item = str_replace(["\u{2014}", "\u{2013}", '—', '–'], [' - ', ' - ', ' - ', ' - '], $item);
                $item = preg_replace('/\s+-\s+/', ' - ', $item) ?? $item;
            }
        });

        return $value;
    }

    /**
     * Replace "post 218" / "post #218" in user-facing copy with a short description.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteInternalPostIds(array $output, array $facts): array
    {
        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            if (isset($output[$field]) && is_string($output[$field])) {
                $output[$field] = $this->rewriteInternalPostIdsInText($output[$field], $facts);
            }
        }

        if (isset($output['watch']) && is_array($output['watch'])) {
            $output['watch'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteInternalPostIdsInText($item, $facts)
                    : $item,
                $output['watch'],
            );
        }

        if (isset($output['actions']) && is_array($output['actions'])) {
            foreach ($output['actions'] as $index => $action) {
                if (! is_array($action)) {
                    continue;
                }

                foreach (['title', 'why', 'how', 'hook'] as $field) {
                    if (isset($action[$field]) && is_string($action[$field])) {
                        $action[$field] = $this->rewriteInternalPostIdsInText($action[$field], $facts);
                    }
                }

                $output['actions'][$index] = $action;
            }
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function rewriteInternalPostIdsInText(string $text, array $facts): string
    {
        $posts = $this->postsById($facts);
        $allowed = $this->allowedPostIds($facts);

        $rewritten = preg_replace_callback(
            self::INTERNAL_POST_ID_PATTERN,
            function (array $match) use ($posts, $allowed): string {
                $id = $this->internalPostIdFromMatch($match);

                if (! $this->isInternalPostIdReference($id, $allowed)) {
                    return $match[0];
                }

                $post = $posts[$id] ?? null;

                return is_array($post) ? $this->shortPostLabel($post) : $match[0];
            },
            $text,
        );

        $rewritten = is_string($rewritten) ? $rewritten : $text;

        return $this->stripEmptyBrackets($rewritten);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function internalPostIdMentions(string $text, array $facts): array
    {
        $allowed = $this->allowedPostIds($facts);
        preg_match_all(self::INTERNAL_POST_ID_PATTERN, $text, $matches, PREG_SET_ORDER);

        $hits = [];

        foreach ($matches as $match) {
            $id = $this->internalPostIdFromMatch($match);

            if ($this->isInternalPostIdReference($id, $allowed)) {
                $hits[] = $match[0];
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @param  array<int, string>  $match
     */
    private function internalPostIdFromMatch(array $match): int
    {
        $first = $match[1] ?? '';
        $second = $match[2] ?? '';

        return (int) ($first !== '' ? $first : $second);
    }

    public function stripEmptyBrackets(string $text): string
    {
        $cleaned = preg_replace('/\(\s*\)|\[\s*\]/', '', $text) ?? $text;
        $cleaned = preg_replace('/\s{2,}/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+([,.;:!?])/u', '$1', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    /**
     * @param  list<int>  $allowed
     */
    public function isInternalPostIdReference(int $id, array $allowed): bool
    {
        return in_array($id, $allowed, true) || $id >= 100;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<int, array<string, mixed>>
     */
    public function postsById(array $facts): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (! is_array($node)) {
                return;
            }

            if (isset($node['post_id']) && is_numeric($node['post_id'])) {
                $found[(int) $node['post_id']] = $node;
            }

            foreach ($node as $value) {
                $walk($value);
            }
        };

        $walk($facts);

        return $found;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    public function shortPostLabel(array $post): string
    {
        $format = trim((string) ($post['format'] ?? ''));
        if ($format === '') {
            $format = 'post';
        }

        $hook = trim((string) ($post['hook'] ?? ''));
        $caption = trim((string) ($post['caption'] ?? ''));
        $title = $hook !== '' ? $hook : $caption;

        if ($title !== '') {
            $words = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $short = implode(' ', array_slice($words, 0, 6));

            return 'their '.$short.' '.$format;
        }

        $handle = $this->normaliseHandle((string) ($post['handle'] ?? ''));
        if ($handle !== '') {
            return '@'.$handle.'\'s '.$format;
        }

        return 'their '.$format;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function allowedHandles(array $facts): array
    {
        $handles = [];

        foreach ([
            ...$this->handleCandidates($facts['allowed_handles'] ?? []),
            ...$this->handleCandidates($facts['brand']['own_handles'] ?? []),
            ...$this->handleCandidates($facts['own']['handle'] ?? null),
        ] as $handle) {
            $normalised = $this->normaliseHandle($handle);
            if ($normalised !== '') {
                $handles[] = $normalised;
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<int>
     */
    public function allowedPostIds(array $facts): array
    {
        return array_values(array_unique(array_map('intval', $facts['allowed_post_ids'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function allowedNumbers(array $facts): array
    {
        $tokens = [];
        $json = json_encode($facts) ?: '';

        if (preg_match_all('/\d[\d,.]*%?x?/u', $json, $matches) > 0) {
            foreach ($matches[0] as $raw) {
                $tokens[] = $this->normaliseNumber((string) $raw);
            }
        }

        for ($i = 1; $i <= 5; $i++) {
            $tokens[] = (string) $i;
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * @return list<string>
     */
    public function handlesInText(string $text): array
    {
        preg_match_all('/@([A-Za-z0-9._]*[A-Za-z0-9_])/u', $text, $matches);

        return array_values(array_unique(array_map(
            fn (string $handle): string => $this->normaliseHandle($handle),
            $matches[1] ?? [],
        )));
    }

    /**
     * @return list<string>
     */
    public function numbersInText(string $text): array
    {
        $withoutClocks = preg_replace('/\b\d{1,2}:\d{2}\b/', ' ', $text) ?? $text;
        preg_match_all('/\d[\d,.]*%?x?/u', $withoutClocks, $matches);

        return array_values(array_unique(array_map(
            fn (string $number): string => $this->normaliseNumber($number),
            $matches[0] ?? [],
        )));
    }

    public function numberIsKnown(string $number, array $allowed): bool
    {
        $normalised = $this->normaliseNumber($number);

        if (in_array($normalised, $allowed, true)) {
            return true;
        }

        $stripped = rtrim(rtrim($normalised, 'x'), '%');

        return in_array($stripped, $allowed, true)
            || in_array($stripped.'x', $allowed, true)
            || in_array($stripped.'%', $allowed, true);
    }

    public function isAllowedSmallInt(string $number): bool
    {
        return preg_match('/^[1-5]$/', $this->normaliseNumber($number)) === 1;
    }

    public function isClock(string $number): bool
    {
        return preg_match('/^\d{1,2}:\d{2}$/', $number) === 1;
    }

    public function containsEllipsis(string $text): bool
    {
        return str_contains($text, '...');
    }

    public function normaliseHandle(string $handle): string
    {
        return strtolower(ltrim(trim($handle), '@'));
    }

    public function normaliseNumber(string $number): string
    {
        $normalised = strtolower(str_replace(',', '', trim($number)));

        // Sentence-end "97." is the same fact number as 97.
        if (preg_match('/^\d+\.$/', $normalised) === 1) {
            return rtrim($normalised, '.');
        }

        return $normalised;
    }

    /**
     * @param  list<string>  $watch
     * @return array<string, mixed>
     */
    private function proseProbe(string $field, mixed $value, array $watch): array
    {
        $safeActions = [
            ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        return [
            'headline' => $field === 'headline' ? (string) $value : 'Plan for today',
            'actions' => $safeActions,
            'own_summary' => $field === 'own_summary' ? (string) $value : '',
            'competitor_summary' => $field === 'competitor_summary' ? (string) $value : '',
            'watch' => $field === 'watch' ? $watch : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function unsupportedDailyClaims(string $text, array $facts): array
    {
        $errors = [];
        $dailyPosters = $this->dailyPosterHandles($facts);
        $almostDailyPosters = $this->almostDailyPosterHandles($facts);
        $handles = $this->allowedHandles($facts);
        $sentences = preg_split('/(?:\R+|(?<=[.!?])\s+)/u', $text) ?: [$text];

        foreach ($sentences as $sentence) {
            if ($this->claimsAlmostDailyPosting($sentence)) {
                $mentioned = $this->handlesMentionedIn($sentence, $handles);

                if ($mentioned === []) {
                    if ($almostDailyPosters === []) {
                        $errors[] = 'unsupported almost-daily claim';
                    }

                    continue;
                }

                foreach ($mentioned as $handle) {
                    if (! in_array($handle, $almostDailyPosters, true)) {
                        $errors[] = 'unsupported almost-daily claim for @'.$handle;
                    }
                }

                continue;
            }

            if (! $this->claimsDailyPosting($sentence)) {
                continue;
            }

            $mentioned = $this->handlesMentionedIn($sentence, $handles);

            if ($mentioned === []) {
                if ($dailyPosters === []) {
                    $errors[] = 'unsupported daily claim';
                }

                continue;
            }

            foreach ($mentioned as $handle) {
                if (! in_array($handle, $dailyPosters, true)) {
                    $errors[] = 'unsupported daily claim for @'.$handle;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedFollowerWindow(string $text, array $facts): ?string
    {
        if (preg_match('/\b(gained|lost|grew|up|down)\b.{0,40}\b(last 7 days|this week)\b|\b(last 7 days|this week)\b.{0,40}\bfollowers?\b/i', $text) !== 1) {
            return null;
        }

        $from = data_get($facts, 'own.followers_change_7d.from_date');
        $briefDate = data_get($facts, 'brief_date');
        $label = data_get($facts, 'own.followers_change_7d.label');

        if (! is_string($from) || $from === '' || ! is_string($briefDate) || $briefDate === '') {
            return 'follower change window is not the last 7 days';
        }

        $expected = CarbonImmutable::parse($briefDate)->subDays(7)->toDateString();

        if ($from === $expected) {
            return null;
        }

        return is_string($label) && $label !== ''
            ? 'follower change window is not the last 7 days; use '.$label
            : 'follower change window is not the last 7 days';
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedWeekBestClaim(string $text, array $facts): ?string
    {
        if (preg_match('/\b(this week|last 7 days|in the last 7|past 7 days|in 7 days)\b/i', $text) !== 1) {
            return null;
        }

        if (preg_match('/\b(best post|times (?:their |the )?usual|times usual)\b/i', $text) !== 1) {
            return null;
        }

        $handles = $this->handlesMentionedIn($text, $this->allowedHandles($facts));
        $allowed = $this->sevenDayTimesUsualNumbers($facts, $handles);

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:x|times (?:their |the )?usual)/i', $text, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $raw) {
            if (! $this->numberIsKnown((string) $raw, $allowed)) {
                return 'week best-post figure is not from the last 7 days';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedLongestGapClaim(string $text, array $facts): ?string
    {
        if (preg_match('/\blongest\b.{0,50}\b(gap|silence|quiet|among|competitor)|(?:posting |content )?gap.{0,40}\blongest\b|\blonger than (?:any|every|all)\b/i', $text) !== 1) {
            return null;
        }

        $gaps = is_array($facts['cadence_gaps'] ?? null) ? $facts['cadence_gaps'] : $this->cadenceGapsFromFacts($facts);
        $longestHandle = $this->normaliseHandle((string) ($gaps['longest_handle'] ?? ''));
        $ownHandle = $this->normaliseHandle((string) data_get($facts, 'own.handle', ''));
        $mentioned = $this->handlesMentionedIn($text, $this->allowedHandles($facts));
        $claimsOwn = preg_match('/\b(your|you have|the brand)\b/i', $text) === 1
            || ($ownHandle !== '' && in_array($ownHandle, $mentioned, true));

        if ($claimsOwn && ($gaps['own_is_longest'] ?? false) !== true) {
            return $this->longestGapError($gaps);
        }

        if (($gaps['own_is_longest'] ?? false) === true) {
            return null;
        }

        if ($longestHandle !== '' && in_array($longestHandle, $mentioned, true)) {
            return null;
        }

        return $this->longestGapError($gaps);
    }

    /**
     * @param  array{own_days: int|null, longest_handle: string|null, longest_days: int|null, own_is_longest: bool}  $gaps
     */
    private function longestGapError(array $gaps): string
    {
        $handle = $this->normaliseHandle((string) ($gaps['longest_handle'] ?? ''));
        $days = $gaps['longest_days'] ?? null;

        if ($handle !== '' && is_numeric($days)) {
            return 'longest-gap claim is false; @'.$handle.' has gone '.(int) $days.' days';
        }

        return 'longest-gap claim is false';
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $handles
     * @return list<string>
     */
    public function sevenDayTimesUsualNumbers(array $facts, array $handles = []): array
    {
        $tokens = [];

        foreach ($this->sevenDayPosts($facts, $handles) as $post) {
            foreach (['times_usual', 'views_vs_usual'] as $key) {
                if (! isset($post[$key]) || ! is_numeric($post[$key])) {
                    continue;
                }

                $tokens[] = $this->normaliseNumber((string) $post[$key]);
                $tokens[] = $this->normaliseNumber(number_format((float) $post[$key], 1, '.', ''));
            }
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $handles
     * @return list<array<string, mixed>>
     */
    public function sevenDayPosts(array $facts, array $handles = []): array
    {
        $wanted = array_values(array_filter(array_map(
            fn (string $handle): string => $this->normaliseHandle($handle),
            $handles,
        )));
        $posts = [];

        foreach ([$facts['own'] ?? null, ...($facts['competitors'] ?? [])] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $handle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($wanted !== [] && $handle !== '' && ! in_array($handle, $wanted, true)) {
                continue;
            }

            foreach (['posts_last_7d', 'best_post_7d'] as $key) {
                $value = $row[$key] ?? null;
                if ($key === 'best_post_7d' && is_array($value) && isset($value['post_id'])) {
                    $posts[] = $value;

                    continue;
                }

                if (! is_array($value)) {
                    continue;
                }

                foreach ($value as $post) {
                    if (is_array($post)) {
                        $posts[] = $post;
                    }
                }
            }
        }

        $top = $facts['top_competitor_hit_7d'] ?? null;
        if (is_array($top)) {
            $handle = $this->normaliseHandle((string) ($top['handle'] ?? ''));
            if ($wanted === [] || $handle === '' || in_array($handle, $wanted, true)) {
                $posts[] = $top;
            }
        }

        return $posts;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{own_days: int|null, longest_handle: string|null, longest_days: int|null, own_is_longest: bool}
     */
    public function cadenceGapsFromFacts(array $facts): array
    {
        $own = is_array($facts['own'] ?? null) ? $facts['own'] : null;
        $ownDays = is_numeric($own['days_since_last_post'] ?? null) ? (int) $own['days_since_last_post'] : null;
        $longestHandle = is_array($own) ? $this->normaliseHandle((string) ($own['handle'] ?? '')) : null;
        $longestDays = $ownDays;

        foreach ($facts['competitors'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $days = is_numeric($row['days_since_last_post'] ?? null) ? (int) $row['days_since_last_post'] : null;

            if ($days === null && (($row['sync_empty'] ?? false) || ($row['last_posted_at'] ?? null) === null)) {
                $days = 999;
            }

            if ($days === null) {
                continue;
            }

            if ($longestDays === null || $days > $longestDays) {
                $longestDays = $days;
                $longestHandle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            }
        }

        return [
            'own_days' => $ownDays,
            'longest_handle' => $longestHandle !== '' ? $longestHandle : null,
            'longest_days' => $longestDays,
            'own_is_longest' => $ownDays !== null && $longestDays !== null && $ownDays >= $longestDays,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function dailyPosterHandles(array $facts): array
    {
        $handles = [];

        foreach ($facts['cadence'] ?? [] as $row) {
            if (is_array($row) && ($row['posted_every_day_last_7'] ?? false)) {
                $normalised = $this->normaliseHandle((string) ($row['handle'] ?? ''));
                if ($normalised !== '') {
                    $handles[] = $normalised;
                }
            }
        }

        foreach ([$facts['own'] ?? null, ...($facts['competitors'] ?? [])] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cadence = is_array($row['cadence'] ?? null) ? $row['cadence'] : [];
            if ($cadence['posted_every_day_last_7'] ?? false) {
                $normalised = $this->normaliseHandle((string) ($row['handle'] ?? ''));
                if ($normalised !== '') {
                    $handles[] = $normalised;
                }
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function almostDailyPosterHandles(array $facts): array
    {
        $handles = [];

        foreach ($facts['cadence'] ?? [] as $row) {
            if (! is_array($row) || ! $this->cadenceLooksAlmostDaily($row)) {
                continue;
            }

            $normalised = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($normalised !== '') {
                $handles[] = $normalised;
            }
        }

        foreach ([$facts['own'] ?? null, ...($facts['competitors'] ?? [])] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cadence = is_array($row['cadence'] ?? null) ? $row['cadence'] : [];
            if (! $this->cadenceLooksAlmostDaily($cadence)) {
                continue;
            }

            $normalised = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($normalised !== '') {
                $handles[] = $normalised;
            }
        }

        return array_values(array_unique($handles));
    }

    public function claimsDailyPosting(string $text): bool
    {
        if (preg_match('/\b(?:posts?|posting|posted)\s+(?:daily|every\s+day)\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\bdaily\s+(?:posts?|posting)\b/i', $text) === 1) {
            return true;
        }

        return preg_match('/\bevery\s+day\b/i', $text) === 1
            && preg_match('/\b(?:posts?|posting|posted)\b/i', $text) === 1;
    }

    public function claimsAlmostDailyPosting(string $text): bool
    {
        if (preg_match('/\b(?:almost|nearly|practically|pretty\s+much)\s+(?:daily|every\s+day)\b/i', $text) === 1) {
            return true;
        }

        return preg_match('/\b(?:posts?|posting|posted)\s+most\s+days\b/i', $text) === 1
            || (
                preg_match('/\bmost\s+days\b/i', $text) === 1
                && preg_match('/\b(?:posts?|posting|posted)\b/i', $text) === 1
            );
    }

    /**
     * @return list<string>
     */
    private function handleCandidates(mixed $value): array
    {
        if (is_string($value) || is_numeric($value)) {
            return [(string) $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $handles = [];

        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item)) {
                $handles[] = (string) $item;
            }
        }

        return $handles;
    }

    /**
     * @param  array<string, mixed>  $cadence
     */
    private function cadenceLooksAlmostDaily(array $cadence): bool
    {
        if (($cadence['posted_almost_daily_last_7'] ?? false) || ($cadence['posted_every_day_last_7'] ?? false)) {
            return true;
        }

        return (int) ($cadence['distinct_days_posted_last_7'] ?? 0) >= 6;
    }

    /**
     * @param  list<string>  $handles
     * @return list<string>
     */
    public function handlesMentionedIn(string $text, array $handles): array
    {
        $found = [];

        foreach ($handles as $handle) {
            $normalised = $this->normaliseHandle($handle);

            if ($normalised === '') {
                continue;
            }

            $pattern = '/(?<![A-Za-z0-9.])@?'.preg_quote($normalised, '/').'(?![A-Za-z0-9.])/i';

            if (preg_match($pattern, $text) === 1) {
                $found[] = $normalised;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteBorrowedCompetitorNames(array $output, array $facts): array
    {
        $names = $this->borrowedCompetitorNames($facts);

        if ($names === []) {
            return $output;
        }

        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            if (isset($output[$field]) && is_string($output[$field])) {
                $output[$field] = $this->borrowedNames->rewriteText($output[$field], $names);
            }
        }

        if (isset($output['watch']) && is_array($output['watch'])) {
            $output['watch'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->borrowedNames->rewriteText($item, $names)
                    : $item,
                $output['watch'],
            );
        }

        if (isset($output['actions']) && is_array($output['actions'])) {
            foreach ($output['actions'] as $index => $action) {
                if (! is_array($action)) {
                    continue;
                }

                foreach (['title', 'why', 'how', 'hook'] as $field) {
                    if (isset($action[$field]) && is_string($action[$field])) {
                        $action[$field] = $this->borrowedNames->rewriteText($action[$field], $names);
                    }
                }

                $output['actions'][$index] = $action;
            }
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function borrowedCompetitorNames(array $facts): array
    {
        if (is_array($facts['borrowed_competitor_names'] ?? null)) {
            return array_values(array_filter(
                $facts['borrowed_competitor_names'],
                fn (mixed $name): bool => is_string($name) && $name !== '',
            ));
        }

        $ownCaptions = [];
        $competitorCaptions = [];

        foreach ($this->captionStrings($facts['own'] ?? null) as $caption) {
            $ownCaptions[] = $caption;
        }

        foreach ($facts['competitors'] ?? [] as $row) {
            foreach ($this->captionStrings($row) as $caption) {
                $competitorCaptions[] = $caption;
            }
        }

        return $this->borrowedNames->borrowedNames($ownCaptions, $competitorCaptions);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedStandoutClaim(string $text, array $facts): ?string
    {
        if (preg_match('/\bbest times?\b|\btop tip\b|\bhit reply\b/i', $text) === 1) {
            return null;
        }

        $wantsStrongPraise = preg_match('/\b(standout|outperformed|(?:biggest\s+)?hit)\b|\btop\s+(?:post|reel|carousel|performer)\b/i', $text) === 1;
        $wantsBest = preg_match('/\bbest\s+(?:post|reel|carousel|performer)\b|\btheir best\b|\bbest recent\b/i', $text) === 1;

        if (! $wantsStrongPraise && ! $wantsBest) {
            return null;
        }

        $handles = $this->handlesMentionedIn($text, $this->allowedHandles($facts));
        $posts = $this->sevenDayPosts($facts, $handles);

        foreach ($facts['competitors'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $handle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($handles !== [] && $handle !== '' && ! in_array($handle, $handles, true)) {
                continue;
            }

            if (is_array($row['best_post_7d'] ?? null)) {
                $posts[] = $row['best_post_7d'];
            }

            foreach ($row['standout_winners'] ?? [] as $winner) {
                if (is_array($winner)) {
                    $posts[] = $winner;
                }
            }
        }

        foreach ([$facts['top_competitor_hit_24h'] ?? null, $facts['top_competitor_hit_7d'] ?? null, $facts['top_competitor_hit'] ?? null] as $top) {
            if (is_array($top)) {
                $posts[] = $top;
            }
        }

        $bestScore = null;
        $hasInWindowBest = false;

        foreach ($posts as $post) {
            if (! is_array($post)) {
                continue;
            }

            $score = $this->postEngagementScore($post);
            if ($score === null) {
                continue;
            }

            $bestScore = $bestScore === null ? $score : max($bestScore, $score);

            if ($this->isInWindowBestForHandle($post, $facts)) {
                $hasInWindowBest = true;
            }
        }

        if ($wantsStrongPraise) {
            if ($bestScore !== null && $bestScore > self::STANDOUT_THRESHOLD) {
                return null;
            }

            return 'standout claim needs times-usual above '.self::STANDOUT_THRESHOLD;
        }

        if ($bestScore !== null && ($bestScore > self::STANDOUT_THRESHOLD || $hasInWindowBest)) {
            return null;
        }

        return 'best-post claim is not supported by in-window engagement';
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return list<string>
     */
    private function captionStrings(mixed $row): array
    {
        if (! is_array($row)) {
            return [];
        }

        $captions = [];

        foreach (['posts_last_7d', 'posts_last_24h', 'posts_yesterday', 'standout_winners'] as $key) {
            foreach ($row[$key] ?? [] as $post) {
                if (is_array($post) && is_string($post['caption'] ?? null) && $post['caption'] !== '') {
                    $captions[] = $post['caption'];
                }
            }
        }

        foreach (['best_post_7d', 'best_post_30d'] as $key) {
            $post = $row[$key] ?? null;
            if (is_array($post) && is_string($post['caption'] ?? null) && $post['caption'] !== '') {
                $captions[] = $post['caption'];
            }
        }

        return $captions;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function postEngagementScore(array $post): ?float
    {
        $score = $post['times_usual'] ?? $post['views_vs_usual'] ?? null;

        return is_numeric($score) ? (float) $score : null;
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $facts
     */
    private function isInWindowBestForHandle(array $post, array $facts): bool
    {
        $postId = isset($post['post_id']) ? (int) $post['post_id'] : 0;
        $handle = $this->normaliseHandle((string) ($post['handle'] ?? ''));

        foreach ([$facts['own'] ?? null, ...($facts['competitors'] ?? [])] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowHandle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($handle !== '' && $rowHandle !== '' && $handle !== $rowHandle) {
                continue;
            }

            $best = $row['best_post_7d'] ?? null;
            if (is_array($best) && $postId > 0 && (int) ($best['post_id'] ?? 0) === $postId) {
                return true;
            }
        }

        foreach (['top_competitor_hit_24h', 'top_competitor_hit_7d'] as $key) {
            $top = $facts[$key] ?? null;
            if (is_array($top) && $postId > 0 && (int) ($top['post_id'] ?? 0) === $postId) {
                return true;
            }
        }

        return false;
    }
}
