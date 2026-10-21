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
        $output = $this->rewriteLinkInBioSticker($output);
        $output = $this->rewriteViewsVsUsualWording($output);
        $output = $this->rewriteReplyToCommentActions($output);
        $output = $this->rewriteZeroCommentReplyActions($output, $facts);
        $output = $this->rewriteInternalPostIds($output, $facts);
        $output = $this->rewriteBorrowedCompetitorNames($output, $facts);
        $output = $this->rewriteUnsupportedStandoutClaims($output, $facts);
        $output = $this->rewriteUnsupportedDailyClaims($output, $facts);
        $output = $this->rewriteLongestGapQualification($output, $facts);
        $output = $this->rewriteUnsupportedLeadsWithPostsClaims($output, $facts);
        $output = $this->rewriteUnsupportedHandleTimesUsualClaims($output, $facts);
        $output = $this->rewriteUnsupportedFormatTimesUsualClaims($output, $facts);
        $output = $this->rewriteUnsupportedHookTimesUsualClaims($output, $facts);
        $output = $this->rewriteUnsupportedPostedDateClaims($output, $facts);
        $output = $this->rewriteMismatchedDateFigureClaims($output, $facts);
        $output = $this->rewriteTimesUsualWithoutBaseline($output, $facts);
        // Week-window softening after format/hook rewrites, so corrected out-of-window Nx claims are caught.
        $output = $this->rewriteUnsupportedWeekBestClaims($output, $facts);
        // Figure/format rewrites can leave "standout" next to a sub-threshold Nx - soften again.
        $output = $this->rewriteUnsupportedStandoutClaims($output, $facts);
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

        foreach ($output['trends'] ?? [] as $item) {
            if (is_string($item) && trim($item) !== '') {
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

            $handleTimesUsualError = $this->unsupportedHandleTimesUsualClaim($text, $facts);
            if ($handleTimesUsualError !== null) {
                $errors[] = $handleTimesUsualError;
            }

            $formatTimesUsualError = $this->unsupportedFormatTimesUsualClaim($text, $facts);
            if ($formatTimesUsualError !== null) {
                $errors[] = $formatTimesUsualError;
            }

            $hookTimesUsualError = $this->unsupportedHookTimesUsualClaim($text, $facts);
            if ($hookTimesUsualError !== null) {
                $errors[] = $hookTimesUsualError;
            }

            $leadsError = $this->unsupportedLeadsWithPostsClaim($text, $facts);
            if ($leadsError !== null) {
                $errors[] = $leadsError;
            }

            $postedDateError = $this->unsupportedPostedDateClaim($text, $facts);
            if ($postedDateError !== null) {
                $errors[] = $postedDateError;
            }

            $dateFigureError = $this->unsupportedDateFigureClaim($text, $facts);
            if ($dateFigureError !== null) {
                $errors[] = $dateFigureError;
            }

            $baselineError = $this->unsupportedTimesUsualWithoutBaseline($text, $facts);
            if ($baselineError !== null) {
                $errors[] = $baselineError;
            }

            $weekStandoutError = $this->unsupportedWeekStandoutClaim($text, $facts);
            if ($weekStandoutError !== null) {
                $errors[] = $weekStandoutError;
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
            $result = $this->validate($probe, $facts);
            if (! $result['ok']) {
                $output[$field] = '';

                continue;
            }

            // Keep claim-checker rewrites; do not leave unrepaired LLM wording that only
            // passes because validate() softened it in a throwaway probe.
            $output[$field] = (string) ($result['output'][$field] ?? $output[$field] ?? '');
        }

        $watch = [];

        foreach ($output['watch'] ?? [] as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            $probe = $this->proseProbe('watch', '', [$item]);
            $result = $this->validate($probe, $facts);
            if ($result['ok']) {
                $rewritten = $result['output']['watch'][0] ?? $item;
                $watch[] = is_string($rewritten) ? $rewritten : $item;
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
     * Instagram Stories use a "link sticker"; "link in bio" is for posts/Reels only.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function rewriteLinkInBioSticker(array $value): array
    {
        array_walk_recursive($value, function (mixed &$item): void {
            if (is_string($item)) {
                $item = $this->rewriteLinkInBioStickerInText($item);
            }
        });

        return $value;
    }

    public function rewriteLinkInBioStickerInText(string $text): string
    {
        return preg_replace('/\blink\s+in\s+bio\s+sticker\b/iu', 'link sticker', $text) ?? $text;
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

        preg_match_all(self::INTERNAL_POST_ID_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($matches === []) {
            return $this->stripEmptyBrackets($text);
        }

        $out = '';
        $cursor = 0;

        foreach ($matches as $match) {
            $full = (string) ($match[0][0] ?? '');
            $offset = (int) ($match[0][1] ?? 0);
            $id = $this->internalPostIdFromOffsetMatch($match);

            $out .= substr($text, $cursor, max(0, $offset - $cursor));

            if (! $this->isInternalPostIdReference($id, $allowed)) {
                $out .= $full;
                $cursor = $offset + strlen($full);

                continue;
            }

            $post = $posts[$id] ?? null;

            if (is_array($post) && $this->isParentheticalPostId($full) && $this->precedingTextAlreadyNamesPost($out, $post)) {
                $replacement = '';
            } elseif (is_array($post)) {
                $replacement = $this->shortPostLabel($post);
            } else {
                $replacement = $full;
            }

            $out .= $replacement;
            $cursor = $offset + strlen($full);
        }

        $out .= substr($text, $cursor);
        $out = $this->collapseStackedPostLabels($out);

        return $this->stripEmptyBrackets($out);
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $match
     */
    private function internalPostIdFromOffsetMatch(array $match): int
    {
        $first = $match[1][0] ?? '';
        $second = $match[2][0] ?? '';

        return (int) ($first !== '' ? $first : $second);
    }

    private function isParentheticalPostId(string $match): bool
    {
        return str_starts_with(ltrim($match), '(');
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function precedingTextAlreadyNamesPost(string $prefix, array $post): bool
    {
        $prefix = rtrim($prefix, " \t\n\r\0\x0B,;:.");

        if ($prefix === '') {
            return false;
        }

        $format = preg_quote($this->postFormat($post), '/');

        return preg_match(
            '/(?:\b(?:their|this|that|the|a|an)\s+|\S+(?:\'|\x{2019})s\s+)?(?:'.$format.'|reel|carousel|image|story|post)s?$/iu',
            $prefix,
        ) === 1;
    }

    public function collapseStackedPostLabels(string $text): string
    {
        $cleaned = preg_replace('/\btheir\s+their\b/iu', 'their', $text) ?? $text;
        $cleaned = preg_replace(
            '/\b(reel|carousel|image|story|post)s?\s+their\s+\'[^\']+\'\s+\1s?\b/iu',
            '$1',
            $cleaned,
        ) ?? $cleaned;
        $cleaned = preg_replace(
            '/\btheir\s+(reel|carousel|image|story|post)s?\s+their\s+\'/iu',
            'their \'',
            $cleaned,
        ) ?? $cleaned;

        return $cleaned;
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
        $format = $this->postFormat($post);
        $title = $this->shortHookTitle($post);

        if ($title !== '') {
            return "their '".$title."' ".$format;
        }

        $handle = $this->normaliseHandle((string) ($post['handle'] ?? ''));
        if ($handle !== '') {
            return '@'.$handle.'\'s '.$format;
        }

        return 'their '.$format;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    public function postFormat(array $post): string
    {
        $format = trim((string) ($post['format'] ?? ''));

        return $format !== '' ? $format : 'post';
    }

    /**
     * First ~5 words of the hook or caption, without a trailing format word.
     *
     * @param  array<string, mixed>  $post
     */
    public function shortHookTitle(array $post): string
    {
        $hook = trim((string) ($post['hook'] ?? ''));
        $caption = trim((string) ($post['caption'] ?? ''));
        $title = $hook !== '' ? $hook : $caption;

        if ($title === '') {
            return '';
        }

        $title = trim(str_replace(['"', "\u{201C}", "\u{201D}"], '', $title));
        $title = str_replace(["'", "\u{2019}"], '', $title);
        $words = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice($words, 0, 5);
        $format = mb_strtolower($this->postFormat($post));

        if ($words !== [] && mb_strtolower((string) end($words)) === $format) {
            array_pop($words);
        }

        return trim(implode(' ', $words));
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

        // Small counts (including zero posts / one post) are always allowed in prose.
        for ($i = 0; $i <= 5; $i++) {
            $tokens[] = (string) $i;
        }

        return array_values(array_unique(array_filter($tokens, fn (string $token): bool => $token !== '')));
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
        return preg_match('/^[0-5]$/', $this->normaliseNumber($number)) === 1;
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
                $mentioned = $this->handlesMentionedIn($sentence, $handles, $facts);

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

            $mentioned = $this->handlesMentionedIn($sentence, $handles, $facts);

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
     * Soften unsupported daily / almost-daily / daily-like wording so a good brief
     * is not discarded for a cadence adjective.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteUnsupportedDailyClaims(array $output, array $facts): array
    {
        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            if (isset($output[$field]) && is_string($output[$field])) {
                $output[$field] = $this->rewriteUnsupportedDailyClaimsInText($output[$field], $facts);
            }
        }

        if (isset($output['watch']) && is_array($output['watch'])) {
            $output['watch'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteUnsupportedDailyClaimsInText($item, $facts)
                    : $item,
                $output['watch'],
            );
        }

        if (isset($output['trends']) && is_array($output['trends'])) {
            $output['trends'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteUnsupportedDailyClaimsInText($item, $facts)
                    : $item,
                $output['trends'],
            );
        }

        if (isset($output['actions']) && is_array($output['actions'])) {
            foreach ($output['actions'] as $index => $action) {
                if (! is_array($action)) {
                    continue;
                }

                foreach (['title', 'why', 'how', 'hook'] as $field) {
                    if (isset($action[$field]) && is_string($action[$field])) {
                        $action[$field] = $this->rewriteUnsupportedDailyClaimsInText($action[$field], $facts);
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
    public function rewriteUnsupportedDailyClaimsInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedDailyClaims($text, $facts) === []) {
            return $text;
        }

        $rewritten = $text;
        $rewritten = preg_replace('/\bdaily[\s\-]?like\b/iu', 'recent', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\bnear[\s\-]?daily\b/iu', 'recent', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\b(?:almost|nearly|practically|pretty\s+much)\s+(?:daily|every\s+day)\b/iu', 'regularly', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\b(?:posts?|posting|posted)\s+most\s+days\b/iu', 'posts often', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\b(?:posts?|posting|posted)\s+daily\b/iu', 'posts', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\bdaily\s+(?:posts?|posting)\b/iu', 'recent posting', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\bevery\s+day\b/iu', 'often', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\s+([,.;:!?])/u', '$1', $rewritten) ?? $rewritten;

        return trim($rewritten);
    }

    /**
     * Qualify "longest gap among all tracked accounts" when an empty-sync tracker exists.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteLongestGapQualification(array $output, array $facts): array
    {
        if (! $this->factsHaveEmptySyncTracker($facts)) {
            return $output;
        }

        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            if (isset($output[$field]) && is_string($output[$field])) {
                $output[$field] = $this->rewriteLongestGapQualificationInText($output[$field]);
            }
        }

        if (isset($output['watch']) && is_array($output['watch'])) {
            $output['watch'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteLongestGapQualificationInText($item)
                    : $item,
                $output['watch'],
            );
        }

        if (isset($output['trends']) && is_array($output['trends'])) {
            $output['trends'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteLongestGapQualificationInText($item)
                    : $item,
                $output['trends'],
            );
        }

        if (isset($output['actions']) && is_array($output['actions'])) {
            foreach ($output['actions'] as $index => $action) {
                if (! is_array($action)) {
                    continue;
                }

                foreach (['title', 'why', 'how', 'hook'] as $field) {
                    if (isset($action[$field]) && is_string($action[$field])) {
                        $action[$field] = $this->rewriteLongestGapQualificationInText($action[$field]);
                    }
                }

                $output['actions'][$index] = $action;
            }
        }

        return $output;
    }

    public function rewriteLongestGapQualificationInText(string $text): string
    {
        if ($text === '' || preg_match('/\blongest\b/i', $text) !== 1) {
            return $text;
        }

        $rewritten = preg_replace(
            '/\bamong all tracked accounts\b/iu',
            'among accounts with posts',
            $text,
        ) ?? $text;

        return preg_replace(
            '/\bamong all (?:the )?accounts\b/iu',
            'among accounts with posts',
            $rewritten,
        ) ?? $rewritten;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function factsHaveEmptySyncTracker(array $facts): bool
    {
        foreach ([$facts['own'] ?? null, ...($facts['competitors'] ?? [])] as $row) {
            if (is_array($row) && ($row['sync_empty'] ?? false) === true) {
                return true;
            }
        }

        return false;
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
        if (! $this->textClaimsWeekWindow($text) || ! $this->textClaimsTimesUsual($text)) {
            return null;
        }

        $pairs = $this->figureHandlePairsInText($text, $this->allowedHandles($facts), $facts);

        if ($pairs === []) {
            return null;
        }

        foreach ($pairs as $pair) {
            $handles = $pair['handle'] !== null ? [$pair['handle']] : [];
            $allowed = $this->sevenDayTimesUsualNumbers($facts, $handles);

            if (! $this->numberIsKnownAsTimesUsual($pair['figure'], $allowed)) {
                return 'week best-post figure is not from the last 7 days';
            }
        }

        return null;
    }

    /**
     * A named competitor's times-usual / Nx claim must match that handle's multipliers in facts.
     * Names may be @handles or display / brand-style names from facts.
     *
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedHandleTimesUsualClaim(string $text, array $facts): ?string
    {
        if (! $this->textClaimsTimesUsual($text)) {
            return null;
        }

        foreach ($this->figureHandlePairsInText($text, $this->allowedHandles($facts), $facts) as $pair) {
            if ($pair['handle'] === null) {
                continue;
            }

            $allowed = $this->handleTimesUsualNumbers($facts, [$pair['handle']]);

            if (! $this->numberIsKnownAsTimesUsual($pair['figure'], $allowed)) {
                return 'times-usual figure does not match @'.$pair['handle'].' posts';
            }
        }

        return null;
    }

    /**
     * Rewrite a misattributed Nx / times-usual figure to that named competitor's best known multiplier.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteUnsupportedHandleTimesUsualClaims(array $output, array $facts): array
    {
        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            if (isset($output[$field]) && is_string($output[$field])) {
                $output[$field] = $this->rewriteUnsupportedHandleTimesUsualClaimsInText($output[$field], $facts);
            }
        }

        if (isset($output['watch']) && is_array($output['watch'])) {
            $output['watch'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteUnsupportedHandleTimesUsualClaimsInText($item, $facts)
                    : $item,
                $output['watch'],
            );
        }

        if (isset($output['trends']) && is_array($output['trends'])) {
            $output['trends'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteUnsupportedHandleTimesUsualClaimsInText($item, $facts)
                    : $item,
                $output['trends'],
            );
        }

        if (isset($output['actions']) && is_array($output['actions'])) {
            foreach ($output['actions'] as $index => $action) {
                if (! is_array($action)) {
                    continue;
                }

                foreach (['title', 'why', 'how', 'hook'] as $field) {
                    if (isset($action[$field]) && is_string($action[$field])) {
                        $action[$field] = $this->rewriteUnsupportedHandleTimesUsualClaimsInText($action[$field], $facts);
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
    public function rewriteUnsupportedHandleTimesUsualClaimsInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedHandleTimesUsualClaim($text, $facts) === null) {
            return $text;
        }

        $rewritten = $text;

        foreach ($this->figureHandlePairsInText($rewritten, $this->allowedHandles($facts), $facts) as $pair) {
            if ($pair['handle'] === null) {
                continue;
            }

            $allowed = $this->handleTimesUsualNumbers($facts, [$pair['handle']]);

            if ($this->numberIsKnownAsTimesUsual($pair['figure'], $allowed)) {
                continue;
            }

            $replacement = $this->handleReplacementFigure($facts, $pair['handle']);

            if ($replacement !== null && $replacement !== $pair['figure']) {
                $rewritten = $this->replaceTimesUsualFigure($rewritten, $pair['figure'], $replacement);
            }
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function handleReplacementFigure(array $facts, string $handle): ?string
    {
        $candidates = [];

        foreach ($this->handlePosts($facts, [$handle]) as $post) {
            foreach (['times_usual', 'views_vs_usual'] as $key) {
                if (! isset($post[$key]) || ! is_numeric($post[$key])) {
                    continue;
                }

                $candidates[] = (float) $post[$key];
            }
        }

        if ($candidates === []) {
            return null;
        }

        rsort($candidates);

        return number_format($candidates[0], 1, '.', '');
    }

    /**
     * A cited Nx figure next to Reel/Carousel/Image must match a post of that format.
     *
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedFormatTimesUsualClaim(string $text, array $facts): ?string
    {
        foreach ($this->figureHandlePairsInText($text, $this->allowedHandles($facts), $facts) as $pair) {
            if ($pair['handle'] === null || $pair['format'] === null) {
                continue;
            }

            if ($this->handleHasFigureInFormat($facts, $pair['handle'], $pair['figure'], $pair['format'])) {
                continue;
            }

            return 'times-usual figure does not match @'.$pair['handle'].' '.$pair['format'].' posts';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteUnsupportedFormatTimesUsualClaims(array $output, array $facts): array
    {
        return $this->mapNarrativeText($output, fn (string $text): string => $this->rewriteUnsupportedFormatTimesUsualClaimsInText($text, $facts));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function rewriteUnsupportedFormatTimesUsualClaimsInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedFormatTimesUsualClaim($text, $facts) === null) {
            return $text;
        }

        $rewritten = $text;

        foreach ($this->figureHandlePairsInText($rewritten, $this->allowedHandles($facts), $facts) as $pair) {
            if ($pair['handle'] === null || $pair['format'] === null) {
                continue;
            }

            if ($this->handleHasFigureInFormat($facts, $pair['handle'], $pair['figure'], $pair['format'])) {
                continue;
            }

            $trueFormat = $this->formatForHandleFigure($facts, $pair['handle'], $pair['figure']);
            if ($trueFormat !== null && strcasecmp($trueFormat, $pair['format']) !== 0) {
                $rewritten = $this->replaceFormatWordNearOffset($rewritten, (int) $pair['offset'], $pair['format'], $trueFormat);

                continue;
            }

            $replacement = $this->figureForHandleFormat($facts, $pair['handle'], $pair['format']);
            if ($replacement !== null && $replacement !== $pair['figure']) {
                $rewritten = $this->replaceTimesUsualFigure($rewritten, $pair['figure'], $replacement);
            }
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * A distinctive hook/caption phrase must not be paired with another post's multiplier.
     *
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedHookTimesUsualClaim(string $text, array $facts): ?string
    {
        foreach ($this->hookFigureMismatches($text, $facts) as $mismatch) {
            return 'times-usual figure does not match @'.$mismatch['handle'].' post "'.$mismatch['phrase'].'"';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteUnsupportedHookTimesUsualClaims(array $output, array $facts): array
    {
        return $this->mapNarrativeText($output, fn (string $text): string => $this->rewriteUnsupportedHookTimesUsualClaimsInText($text, $facts));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function rewriteUnsupportedHookTimesUsualClaimsInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedHookTimesUsualClaim($text, $facts) === null) {
            return $text;
        }

        $rewritten = $text;
        $seen = [];

        foreach ($this->hookFigureMismatches($rewritten, $facts) as $mismatch) {
            $key = $mismatch['figure'].'@'.$mismatch['offset'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $figure = $mismatch['figure'];
            $expected = $mismatch['expected_figure'];
            $format = $mismatch['format'];
            $expectedFormat = $mismatch['expected_format'];

            if ($expected !== null && $expected !== $figure) {
                $rewritten = $this->replaceTimesUsualFigure($rewritten, $figure, $expected);
            }

            if ($format !== null && $expectedFormat !== null && strcasecmp($format, $expectedFormat) !== 0) {
                $rewritten = $this->replaceFormatWordNearOffset($rewritten, (int) $mismatch['offset'], $format, $expectedFormat);
            }
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * "X leads with N posts this week" must match the true weekly count and ranking.
     *
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedLeadsWithPostsClaim(string $text, array $facts): ?string
    {
        foreach ($this->leadsWithPostsClaimsInText($text, $facts) as $claim) {
            if ($claim['count'] !== $claim['actual'] || ! $claim['is_leader']) {
                return '@'.$claim['handle'].' does not lead with '.$claim['count'].' posts this week';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteUnsupportedLeadsWithPostsClaims(array $output, array $facts): array
    {
        return $this->mapNarrativeText($output, fn (string $text): string => $this->rewriteUnsupportedLeadsWithPostsClaimsInText($text, $facts));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function rewriteUnsupportedLeadsWithPostsClaimsInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedLeadsWithPostsClaim($text, $facts) === null) {
            return $text;
        }

        $rewritten = $text;
        $leader = $this->weeklyPostLeader($facts);

        foreach ($this->leadsWithPostsClaimsInText($rewritten, $facts) as $claim) {
            if ($claim['count'] === $claim['actual'] && $claim['is_leader']) {
                continue;
            }

            if ($leader !== null && $leader['handle'] !== $claim['handle']) {
                $pattern = '/'.preg_quote($claim['matched'], '/').'/iu';
                // Keep the original account on the clause so a following standout Nx stays bound to them.
                $replacement = $claim['alias'].' posted '.$claim['actual'].' times';
                if ($leader['count'] > $claim['actual']) {
                    $replacement = $leader['alias'].' leads with '.$leader['count'].' posts; '.$claim['alias'].' posted '.$claim['actual'].' times';
                }
                $rewritten = preg_replace($pattern, $replacement, $rewritten, 1) ?? $rewritten;

                continue;
            }

            $pattern = '/'.preg_quote($claim['matched'], '/').'/iu';
            $replacement = $claim['alias'].' posted '.$claim['actual'].' times';
            $rewritten = preg_replace($pattern, $replacement, $rewritten, 1) ?? $rewritten;
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * Dates next to "posted" must be post dates from facts, not event dates from captions.
     *
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedPostedDateClaim(string $text, array $facts): ?string
    {
        foreach ($this->postedDateClaimsInText($text, $facts) as $claim) {
            if (! $claim['matched_post']) {
                return 'posted-date claim is not a post date in facts';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteUnsupportedPostedDateClaims(array $output, array $facts): array
    {
        return $this->mapNarrativeText($output, fn (string $text): string => $this->rewriteUnsupportedPostedDateClaimsInText($text, $facts));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function rewriteUnsupportedPostedDateClaimsInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedPostedDateClaim($text, $facts) === null) {
            return $text;
        }

        $rewritten = $text;

        foreach ($this->postedDateClaimsInText($rewritten, $facts) as $claim) {
            if ($claim['matched_post']) {
                continue;
            }

            $rewritten = preg_replace('/\bposted(?:\s+on)?\s+'.$claim['date_pattern'].'\b/iu', 'posted', $rewritten) ?? $rewritten;
            $rewritten = preg_replace('/\bfrom\s+'.$claim['date_pattern'].'\b/iu', '', $rewritten) ?? $rewritten;
            $rewritten = preg_replace('/\b'.$claim['date_pattern'].'\b(?:\s+posted(?:\s+that day)?)/iu', 'posted', $rewritten) ?? $rewritten;
            $rewritten = preg_replace('/\bposted that day\b/iu', 'posted', $rewritten) ?? $rewritten;
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * Drop Nx claims for a named account that has no usable times_usual / views_vs_usual.
     *
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedTimesUsualWithoutBaseline(string $text, array $facts): ?string
    {
        foreach ($this->figureHandlePairsInText($text, $this->allowedHandles($facts), $facts) as $pair) {
            if ($pair['handle'] === null) {
                continue;
            }

            if ($this->handleTimesUsualNumbers($facts, [$pair['handle']]) === []) {
                return 'times-usual claim has no usable baseline for @'.$pair['handle'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteTimesUsualWithoutBaseline(array $output, array $facts): array
    {
        return $this->mapNarrativeText($output, fn (string $text): string => $this->rewriteTimesUsualWithoutBaselineInText($text, $facts));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function rewriteTimesUsualWithoutBaselineInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedTimesUsualWithoutBaseline($text, $facts) === null) {
            return $text;
        }

        $rewritten = $text;

        foreach ($this->figureHandlePairsInText($rewritten, $this->allowedHandles($facts), $facts) as $pair) {
            if ($pair['handle'] === null) {
                continue;
            }

            if ($this->handleTimesUsualNumbers($facts, [$pair['handle']]) !== []) {
                continue;
            }

            $rewritten = preg_replace(
                '/(?:,\s*)?(?:at\s+|got\s+|hit\s+)?'.preg_quote($pair['figure'], '/').'\s*(?:[x×]|times (?:their |your |the )?usual(?:\s+views)?)/iu',
                '',
                $rewritten,
            ) ?? $rewritten;
        }

        $rewritten = preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\s+([,.;:!?])/u', '$1', $rewritten) ?? $rewritten;

        return trim($rewritten);
    }

    /**
     * Prefer "comment on the post" over replying to someone else's comment on a competitor post.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public function rewriteReplyToCommentActions(array $output): array
    {
        return $this->mapNarrativeText($output, fn (string $text): string => $this->rewriteReplyToCommentActionsInText($text));
    }

    public function rewriteReplyToCommentActionsInText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Prefer "comment on their mansion Reel" over "comment on the post on their mansion Reel".
        $rewritten = preg_replace(
            '/\breply(?:ing)?\s+to\s+(?:someone else\'s|somebody else\'s|another (?:user|person|member)\'s|a stranger\'s)\s+comments?\s+on\b/iu',
            'comment on',
            $text,
        ) ?? $text;
        $rewritten = preg_replace(
            '/\breply(?:ing)?\s+to\s+(?:someone else\'s|somebody else\'s|another (?:user|person|member)\'s|a stranger\'s)\s+comments?\b/iu',
            'comment on the post',
            $rewritten,
        ) ?? $rewritten;
        $rewritten = preg_replace(
            '/\breply(?:ing)?\s+under\s+(?:someone else\'s|somebody else\'s|another)\s+comments?\b/iu',
            'comment on the post',
            $rewritten,
        ) ?? $rewritten;
        $rewritten = preg_replace(
            '/\breply(?:ing)?\s+to\s+(?:a\s+)?comments?\s+on\s+(?:their|@\w[\w.]*)\s+(?:post|reel|carousel|image)\b/iu',
            'comment on the post',
            $rewritten,
        ) ?? $rewritten;

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * Never suggest replying to comments on a post that has zero comments.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteZeroCommentReplyActions(array $output, array $facts): array
    {
        if (! isset($output['actions']) || ! is_array($output['actions'])) {
            return $output;
        }

        foreach ($output['actions'] as $index => $action) {
            if (! is_array($action)) {
                continue;
            }

            $related = $action['related_post_ids'] ?? [];
            if (! is_array($related) || $related === []) {
                continue;
            }

            $ids = array_values(array_filter(array_map('intval', $related)));
            if ($ids === [] || ! $this->postsHaveZeroComments($facts, $ids)) {
                continue;
            }

            foreach (['title', 'why', 'how', 'hook'] as $field) {
                if (! isset($action[$field]) || ! is_string($action[$field])) {
                    continue;
                }

                $action[$field] = $this->rewriteZeroCommentReplyInText($action[$field]);
            }

            $output['actions'][$index] = $action;
        }

        return $output;
    }

    public function rewriteZeroCommentReplyInText(string $text): string
    {
        if ($text === '' || preg_match('/\breply(?:ing)?\s+to\s+(?:the\s+|their\s+|its\s+)?comments?\b/iu', $text) !== 1) {
            return $text;
        }

        $rewritten = preg_replace(
            '/\breply(?:ing)?\s+to\s+(?:the\s+|their\s+|its\s+)?comments?\b/iu',
            'comment on the post',
            $text,
        ) ?? $text;

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<int>  $postIds
     */
    private function postsHaveZeroComments(array $facts, array $postIds): bool
    {
        $wanted = array_fill_keys($postIds, true);
        $saw = false;

        foreach ($this->handlePosts($facts, []) as $post) {
            if (! is_array($post)) {
                continue;
            }

            $id = (int) ($post['post_id'] ?? 0);
            if ($id < 1 || ! isset($wanted[$id])) {
                continue;
            }

            $saw = true;
            if ((int) ($post['comments'] ?? 0) > 0) {
                return false;
            }
        }

        return $saw;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  callable(string): string  $mapper
     * @return array<string, mixed>
     */
    private function mapNarrativeText(array $output, callable $mapper): array
    {
        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            if (isset($output[$field]) && is_string($output[$field])) {
                $output[$field] = $mapper($output[$field]);
            }
        }

        if (isset($output['watch']) && is_array($output['watch'])) {
            $output['watch'] = array_map(
                fn (mixed $item): mixed => is_string($item) ? $mapper($item) : $item,
                $output['watch'],
            );
        }

        if (isset($output['trends']) && is_array($output['trends'])) {
            $output['trends'] = array_map(
                fn (mixed $item): mixed => is_string($item) ? $mapper($item) : $item,
                $output['trends'],
            );
        }

        if (isset($output['actions']) && is_array($output['actions'])) {
            foreach ($output['actions'] as $index => $action) {
                if (! is_array($action)) {
                    continue;
                }

                foreach (['title', 'why', 'how', 'hook'] as $field) {
                    if (isset($action[$field]) && is_string($action[$field])) {
                        $action[$field] = $mapper($action[$field]);
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
    private function handleHasFigureInFormat(array $facts, string $handle, string $figure, string $format): bool
    {
        foreach ($this->handlePosts($facts, [$handle]) as $post) {
            if (! is_array($post)) {
                continue;
            }

            if (strcasecmp((string) ($post['format'] ?? ''), $format) !== 0) {
                continue;
            }

            foreach (['times_usual', 'views_vs_usual'] as $key) {
                if (! isset($post[$key]) || ! is_numeric($post[$key])) {
                    continue;
                }

                if ($this->numberIsKnownAsTimesUsual($figure, [
                    $this->normaliseNumber((string) $post[$key]),
                    $this->normaliseNumber(number_format((float) $post[$key], 1, '.', '')),
                ])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function formatForHandleFigure(array $facts, string $handle, string $figure): ?string
    {
        foreach ($this->handlePosts($facts, [$handle]) as $post) {
            if (! is_array($post)) {
                continue;
            }

            foreach (['times_usual', 'views_vs_usual'] as $key) {
                if (! isset($post[$key]) || ! is_numeric($post[$key])) {
                    continue;
                }

                if ($this->numberIsKnownAsTimesUsual($figure, [
                    $this->normaliseNumber((string) $post[$key]),
                    $this->normaliseNumber(number_format((float) $post[$key], 1, '.', '')),
                ])) {
                    $format = trim((string) ($post['format'] ?? ''));

                    return $format !== '' ? $format : null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function figureForHandleFormat(array $facts, string $handle, string $format): ?string
    {
        $candidates = [];

        foreach ($this->handlePosts($facts, [$handle]) as $post) {
            if (! is_array($post) || strcasecmp((string) ($post['format'] ?? ''), $format) !== 0) {
                continue;
            }

            foreach (['times_usual', 'views_vs_usual'] as $key) {
                if (isset($post[$key]) && is_numeric($post[$key])) {
                    $candidates[] = (float) $post[$key];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        rsort($candidates);

        return number_format($candidates[0], 1, '.', '');
    }

    private function replaceFormatWordNearOffset(string $text, int $offset, string $from, string $to): string
    {
        // PREG_OFFSET_CAPTURE is byte-based; keep substr in the same space.
        $radius = 48;
        $start = max(0, $offset - $radius);
        $length = min(strlen($text) - $start, ($offset - $start) + $radius);
        $window = substr($text, $start, $length);

        if (preg_match_all('/\b'.preg_quote($from, '/').'\b/iu', $window, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $text;
        }

        $bestLocal = null;
        $bestDistance = PHP_INT_MAX;
        $relativeOffset = $offset - $start;

        foreach ($matches[0] as $match) {
            $local = (int) $match[1];
            $distance = abs($local - $relativeOffset);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestLocal = ['offset' => $local, 'length' => strlen($match[0])];
            }
        }

        if ($bestLocal === null) {
            return $text;
        }

        $absolute = $start + $bestLocal['offset'];

        return substr($text, 0, $absolute).$to.substr($text, $absolute + $bestLocal['length']);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{handle: string, phrase: string, figure: string, expected_figure: ?string, format: ?string, expected_format: ?string, offset: int}>
     */
    private function hookFigureMismatches(string $text, array $facts): array
    {
        $mismatches = [];
        $lower = mb_strtolower($text);

        foreach ($this->accountRowsFromFacts($facts) as $row) {
            $handle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($handle === '') {
                continue;
            }

            $posts = $this->handlePosts($facts, [$handle]);
            foreach ($posts as $post) {
                if (! is_array($post)) {
                    continue;
                }

                foreach ($this->distinctivePostPhrases($post) as $phrase) {
                    $pos = mb_stripos($lower, mb_strtolower($phrase));
                    if ($pos === false) {
                        continue;
                    }

                    foreach ($this->figureHandlePairsInText($text, [$handle], $facts) as $pair) {
                        if ($pair['handle'] !== $handle) {
                            continue;
                        }

                        // Only bind when the figure is near the phrase.
                        if (abs((int) $pair['offset'] - $pos) > 120) {
                            continue;
                        }

                        $expected = $this->postEngagementScore($post);
                        if ($expected === null) {
                            continue;
                        }

                        $expectedFigure = number_format($expected, 1, '.', '');
                        if ($this->numberIsKnownAsTimesUsual($pair['figure'], [$expectedFigure, $this->normaliseNumber((string) $expected)])) {
                            continue;
                        }

                        // Figure matches another of this handle's posts - phrase/post mismatch.
                        if (! $this->numberIsKnownAsTimesUsual($pair['figure'], $this->handleTimesUsualNumbers($facts, [$handle]))) {
                            continue;
                        }

                        $mismatches[] = [
                            'handle' => $handle,
                            'phrase' => $phrase,
                            'figure' => $pair['figure'],
                            'expected_figure' => $expectedFigure,
                            'format' => $pair['format'],
                            'expected_format' => trim((string) ($post['format'] ?? '')) ?: null,
                            'offset' => (int) $pair['offset'],
                        ];
                    }
                }
            }
        }

        return $mismatches;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return list<string>
     */
    private function distinctivePostPhrases(array $post): array
    {
        $phrases = [];

        foreach (['hook', 'caption'] as $key) {
            $value = trim((string) ($post[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
            $words = preg_split('/\s+/u', $value) ?: [];
            $words = array_values(array_filter($words, fn (string $word): bool => mb_strlen(trim($word, " \t\n\r\0\x0B#@.,!?")) >= 3));

            if (count($words) >= 2) {
                $phrases[] = trim($words[0].' '.$words[1], " \t\n\r\0\x0B#@.,!?");
            }

            if (count($words) >= 3) {
                $phrases[] = trim($words[0].' '.$words[1].' '.$words[2], " \t\n\r\0\x0B#@.,!?");
            }

            foreach ($words as $word) {
                $clean = trim($word, " \t\n\r\0\x0B#@.,!?");
                if (mb_strlen($clean) >= 6) {
                    $phrases[] = $clean;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $phrases,
            fn (string $phrase): bool => mb_strlen($phrase) >= 6,
        )));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{handle: string, alias: string, matched: string, count: int, actual: int, is_leader: bool}>
     */
    private function leadsWithPostsClaimsInText(string $text, array $facts): array
    {
        $counts = $this->weeklyPostCounts($facts);
        if ($counts === []) {
            return [];
        }

        $max = max(array_column($counts, 'count'));
        $claims = [];

        foreach ($counts as $row) {
            foreach ($row['aliases'] as $alias) {
                $pattern = '/('.preg_quote($alias, '/').')\s+leads?\s+with\s+(\d+)\s+posts?\b/iu';
                if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) < 1) {
                    continue;
                }

                foreach ($matches as $match) {
                    $claimed = (int) $match[2];
                    $claims[] = [
                        'handle' => $row['handle'],
                        'alias' => $match[1],
                        'matched' => $match[0],
                        'count' => $claimed,
                        'actual' => $row['count'],
                        'is_leader' => $row['count'] === $max && $row['count'] > 0,
                    ];
                }
            }
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{handle: string, alias: string, count: int, aliases: list<string>}>
     */
    private function weeklyPostCounts(array $facts): array
    {
        $rows = [];

        foreach ($this->accountRowsFromFacts($facts) as $row) {
            $handle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($handle === '') {
                continue;
            }

            $count = $row['posts_last_7d_count'] ?? null;
            if (! is_numeric($count)) {
                $count = is_array($row['posts_last_7d'] ?? null) ? count($row['posts_last_7d']) : 0;
            }

            $rows[] = [
                'handle' => $handle,
                'alias' => $this->preferredAliasForAccount($row),
                'count' => (int) $count,
                'aliases' => $this->aliasesForAccount($row),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{handle: string, alias: string, count: int}|null
     */
    private function weeklyPostLeader(array $facts): ?array
    {
        $rows = $this->weeklyPostCounts($facts);
        if ($rows === []) {
            return null;
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $leader = $rows[0];

        if ($leader['count'] <= 0) {
            return null;
        }

        return [
            'handle' => $leader['handle'],
            'alias' => $leader['alias'],
            'count' => $leader['count'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function preferredAliasForAccount(array $row): string
    {
        $display = trim((string) ($row['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }

        $handle = $this->normaliseHandle((string) ($row['handle'] ?? ''));

        return $handle !== '' ? '@'.$handle : 'they';
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{date_pattern: string, matched_post: bool}>
     */
    private function postedDateClaimsInText(string $text, array $facts): array
    {
        $claims = [];
        $datePattern = '(\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2})';

        if (preg_match_all(
            '/\bposted(?:\s+on)?\s+'.$datePattern.'\b|\bfrom\s+'.$datePattern.'\b|\b'.$datePattern.'\b.{0,30}\bposted(?:\s+that day)?\b/iu',
            $text,
            $matches,
            PREG_SET_ORDER,
        ) < 1) {
            return [];
        }

        $postDates = $this->postDateTokens($facts);

        foreach ($matches as $match) {
            $raw = '';
            foreach (array_slice($match, 1) as $group) {
                if (is_string($group) && trim($group) !== '') {
                    $raw = trim($group);
                    break;
                }
            }
            if ($raw === '') {
                continue;
            }

            $claims[] = [
                'date_pattern' => preg_quote($raw, '/'),
                'matched_post' => $this->dateMatchesPostTokens($raw, $postDates),
            ];
        }

        return $claims;
    }

    /**
     * A date next to an Nx figure must belong to the same post as that figure (and format).
     *
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedDateFigureClaim(string $text, array $facts): ?string
    {
        foreach ($this->dateFigureClaimsInText($text, $facts) as $claim) {
            if (! $claim['matched_post']) {
                return 'date and times-usual figure are not from the same post';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteMismatchedDateFigureClaims(array $output, array $facts): array
    {
        return $this->mapNarrativeText($output, fn (string $text): string => $this->rewriteMismatchedDateFigureClaimsInText($text, $facts));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function rewriteMismatchedDateFigureClaimsInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedDateFigureClaim($text, $facts) === null) {
            return $text;
        }

        $rewritten = $text;

        foreach ($this->dateFigureClaimsInText($rewritten, $facts) as $claim) {
            if ($claim['matched_post']) {
                continue;
            }

            $replacementDate = $claim['expected_date'];
            if (is_string($replacementDate) && $replacementDate !== '') {
                $rewritten = preg_replace(
                    '/\b(?:from|posted(?:\s+on)?)\s+'.$claim['date_pattern'].'\b/iu',
                    'from '.$replacementDate,
                    $rewritten,
                    1,
                ) ?? $rewritten;

                continue;
            }

            $rewritten = preg_replace(
                '/\s*\b(?:from|posted(?:\s+on)?)\s+'.$claim['date_pattern'].'\b/iu',
                '',
                $rewritten,
                1,
            ) ?? $rewritten;
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{date_pattern: string, matched_post: bool, expected_date: ?string, figure: string, handle: ?string, format: ?string}>
     */
    private function dateFigureClaimsInText(string $text, array $facts): array
    {
        $datePattern = '(\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2})';
        $claims = [];

        if (preg_match_all(
            '/\b(?:from|posted(?:\s+on)?)\s+'.$datePattern.'\b/iu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) < 1) {
            return [];
        }

        $pairs = $this->figureHandlePairsInText($text, $this->allowedHandles($facts), $facts);

        foreach ($matches[1] as $index => $dateMatch) {
            $rawDate = trim((string) $dateMatch[0]);
            $dateOffset = (int) $dateMatch[1];
            if ($rawDate === '') {
                continue;
            }

            $nearest = null;
            $nearestDistance = PHP_INT_MAX;
            foreach ($pairs as $pair) {
                $distance = abs((int) $pair['offset'] - $dateOffset);
                if ($distance > 80 || $distance >= $nearestDistance) {
                    continue;
                }
                $nearestDistance = $distance;
                $nearest = $pair;
            }

            if ($nearest === null) {
                continue;
            }

            $post = $this->postMatchingHandleFigureFormat(
                $facts,
                $nearest['handle'],
                $nearest['figure'],
                $nearest['format'],
            );
            $matched = $post !== null && $this->postMatchesDateToken($post, $rawDate);
            $expectedDate = null;
            if ($post !== null && ! $matched) {
                $expectedDate = $this->shortDateForPost($post);
            }

            $claims[] = [
                'date_pattern' => preg_quote($rawDate, '/'),
                'matched_post' => $matched,
                'expected_date' => $expectedDate,
                'figure' => $nearest['figure'],
                'handle' => $nearest['handle'],
                'format' => $nearest['format'],
            ];
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>|null
     */
    private function postMatchingHandleFigureFormat(array $facts, ?string $handle, string $figure, ?string $format): ?array
    {
        $handles = $handle !== null && $handle !== '' ? [$handle] : [];

        foreach ($this->handlePosts($facts, $handles) as $post) {
            if (! is_array($post)) {
                continue;
            }

            $score = $this->postEngagementScore($post);
            if ($score === null) {
                continue;
            }

            $candidates = [
                number_format($score, 1, '.', ''),
                $this->normaliseNumber((string) $score),
            ];
            if (! $this->numberIsKnownAsTimesUsual($figure, $candidates)) {
                continue;
            }

            if ($format !== null && $format !== '') {
                $postFormat = trim((string) ($post['format'] ?? ''));
                if ($postFormat !== '' && strcasecmp($postFormat, $format) !== 0) {
                    continue;
                }
            }

            return $post;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function postMatchesDateToken(array $post, string $rawDate): bool
    {
        $tokens = [];
        $label = (string) ($post['posted_label'] ?? '');
        if ($label !== '') {
            $tokens[] = mb_strtolower($label);
        }
        $postedAt = (string) ($post['posted_at'] ?? '');
        if ($postedAt !== '') {
            try {
                $london = CarbonImmutable::parse($postedAt)->timezone('Europe/London');
                $tokens[] = mb_strtolower($london->format('j M'));
                $tokens[] = mb_strtolower($london->format('j F'));
                $tokens[] = mb_strtolower($london->format('d M'));
            } catch (\Throwable) {
                // Ignore.
            }
        }

        return $this->dateMatchesPostTokens($rawDate, $tokens);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function shortDateForPost(array $post): ?string
    {
        $postedAt = (string) ($post['posted_at'] ?? '');
        if ($postedAt !== '') {
            try {
                return CarbonImmutable::parse($postedAt)->timezone('Europe/London')->format('j M');
            } catch (\Throwable) {
                // Fall through to label.
            }
        }

        $label = (string) ($post['posted_label'] ?? '');
        if (preg_match('/\b(\d{1,2}\s+[A-Za-z]{3,9})\b/', $label, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private function postDateTokens(array $facts): array
    {
        $tokens = [];

        foreach ($this->handlePosts($facts, []) as $post) {
            if (! is_array($post)) {
                continue;
            }

            $label = (string) ($post['posted_label'] ?? '');
            if ($label !== '') {
                $tokens[] = mb_strtolower($label);
            }

            $postedAt = (string) ($post['posted_at'] ?? '');
            if ($postedAt !== '') {
                try {
                    $london = CarbonImmutable::parse($postedAt)->timezone('Europe/London');
                    $tokens[] = mb_strtolower($london->format('j M'));
                    $tokens[] = mb_strtolower($london->format('j F'));
                    $tokens[] = mb_strtolower($london->format('d M'));
                    $tokens[] = mb_strtolower($london->isoFormat('D MMM'));
                    $tokens[] = mb_strtolower($london->isoFormat('D MMMM'));
                } catch (\Throwable) {
                    // Ignore unparseable dates.
                }
            }
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function dateMatchesPostTokens(string $raw, array $tokens): bool
    {
        $needle = mb_strtolower(trim($raw));
        $needle = preg_replace('/\s+/u', ' ', $needle) ?? $needle;

        foreach ($tokens as $token) {
            if (str_contains($token, $needle) || str_contains($needle, $token)) {
                return true;
            }

            // "1 Nov" vs "1 November" / "01 Nov".
            $compactNeedle = preg_replace('/\bsept\b/i', 'sep', $needle) ?? $needle;
            $compactToken = preg_replace('/\bsept\b/i', 'sep', $token) ?? $token;
            if (preg_match('/\b(\d{1,2})\s+([a-z]+)/i', $compactNeedle, $n) === 1
                && preg_match('/\b(\d{1,2})\s+([a-z]+)/i', $compactToken, $t) === 1
            ) {
                if ((int) $n[1] === (int) $t[1] && str_starts_with($t[2], mb_substr($n[2], 0, 3))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Standout / hit praise tied to "this week" must use an in-window post, not a 30-day winner.
     *
     * @param  array<string, mixed>  $facts
     */
    public function unsupportedWeekStandoutClaim(string $text, array $facts): ?string
    {
        if (! $this->textClaimsWeekWindow($text)) {
            return null;
        }

        if (preg_match('/\b(standout|outperformed|(?:biggest\s+)?hit)\b|\btop\s+(?:post|reel|carousel|performer)\b/i', $text) !== 1) {
            return null;
        }

        $handles = $this->handlesMentionedIn($text, $this->allowedHandles($facts), $facts);
        $posts = $this->sevenDayPosts($facts, $handles);

        foreach ($posts as $post) {
            if (! is_array($post)) {
                continue;
            }

            $score = $this->postEngagementScore($post);
            if ($score !== null && $score > self::STANDOUT_THRESHOLD) {
                return null;
            }
        }

        return 'week standout claim is not from the last 7 days';
    }

    /**
     * Rewrite out-of-window week-best figures to the handle's in-window best, or soften
     * the week wording so a legitimate recent figure is not rejected.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteUnsupportedWeekBestClaims(array $output, array $facts): array
    {
        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            if (isset($output[$field]) && is_string($output[$field])) {
                $output[$field] = $this->rewriteUnsupportedWeekBestClaimsInText($output[$field], $facts);
            }
        }

        if (isset($output['watch']) && is_array($output['watch'])) {
            $output['watch'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteUnsupportedWeekBestClaimsInText($item, $facts)
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
                        $action[$field] = $this->rewriteUnsupportedWeekBestClaimsInText($action[$field], $facts);
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
    public function rewriteUnsupportedWeekBestClaimsInText(string $text, array $facts): string
    {
        if ($text === '') {
            return $text;
        }

        $needsWeekRewrite = $this->unsupportedWeekBestClaim($text, $facts) !== null
            || $this->unsupportedWeekStandoutClaim($text, $facts) !== null;

        if (! $needsWeekRewrite) {
            return $text;
        }

        $rewritten = $text;

        foreach ($this->figureHandlePairsInText($rewritten, $this->allowedHandles($facts), $facts) as $pair) {
            $handles = $pair['handle'] !== null ? [$pair['handle']] : [];
            $allowed = $this->sevenDayTimesUsualNumbers($facts, $handles);

            if ($this->numberIsKnownAsTimesUsual($pair['figure'], $allowed)) {
                continue;
            }

            $replacement = $this->sevenDayReplacementFigure($facts, $handles, $pair['figure']);

            if ($replacement !== null && $replacement !== $pair['figure']) {
                $rewritten = $this->replaceTimesUsualFigure($rewritten, $pair['figure'], $replacement);
            }
        }

        if ($this->unsupportedWeekBestClaim($rewritten, $facts) !== null
            || $this->unsupportedWeekStandoutClaim($rewritten, $facts) !== null) {
            // Soften the week window so an out-of-window standout is not treated as a 7-day claim.
            $rewritten = preg_replace(
                '/\b(this week|last 7 days|in the last 7 days|past 7 days|in 7 days)\b/iu',
                'recently',
                $rewritten,
            ) ?? $rewritten;
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function rewriteViewsVsUsualWording(array $value): array
    {
        array_walk_recursive($value, function (mixed &$item): void {
            if (is_string($item)) {
                $item = $this->rewriteViewsVsUsualWordingInText($item);
            }
        });

        return $value;
    }

    public function rewriteViewsVsUsualWordingInText(string $text): string
    {
        $rewritten = preg_replace(
            '/(\d+(?:\.\d+)?)\s*(?:x\s+)?views vs(?: their)? usual/iu',
            '$1 times their usual views',
            $text,
        ) ?? $text;

        // Bare "times usual" (no "views") is engagement wording.
        $rewritten = preg_replace(
            '/(\d+(?:\.\d+)?)\s*times usual(?!\s+views)\b/iu',
            '$1 times their usual',
            $rewritten,
        ) ?? $rewritten;

        return $rewritten;
    }

    public function textClaimsWeekWindow(string $text): bool
    {
        return preg_match('/\b(this week|last 7 days|in the last 7|past 7 days|in 7 days)\b/i', $text) === 1;
    }

    public function textClaimsTimesUsual(string $text): bool
    {
        if (preg_match('/\b(best post|times (?:their |your |the )?usual|times usual)\b/i', $text) === 1) {
            return true;
        }

        // Decimal Nx (2.3x) is a times-usual shorthand. Bare "2x" often means "twice".
        return preg_match('/\d+\.\d+\s*[x×]/iu', $text) === 1;
    }

    /**
     * @return list<string>
     */
    public function timesUsualFiguresInText(string $text): array
    {
        return array_values(array_unique(array_map(
            fn (array $pair): string => $pair['figure'],
            $this->figureHandlePairsInText($text, []),
        )));
    }

    /**
     * Pair each times-usual / Nx figure with the nearest preceding named competitor
     * (@handle, handle token, display_name, or handle-derived brand phrase) and any
     * nearby format word (Reel / Carousel / Image).
     *
     * @param  list<string>  $allowedHandles
     * @param  array<string, mixed>  $facts
     * @return list<array{figure: string, handle: ?string, format: ?string, offset: int}>
     */
    public function figureHandlePairsInText(string $text, array $allowedHandles, array $facts = []): array
    {
        $mentions = $this->competitorMentionsInText($text, $allowedHandles, $facts);

        $pairs = [];
        $figurePattern = '/(\d+(?:\.\d+)?)\s*(?:[x×]|times (?:their |your |the )?usual)/iu';

        if (preg_match_all($figurePattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[1] as $index => $match) {
                $figure = $this->normaliseNumber($match[0]);
                $offset = (int) $match[1];
                $fullOffset = (int) ($matches[0][$index][1] ?? $offset);
                $handle = null;

                foreach ($mentions as $candidate) {
                    if ($candidate['offset'] <= $offset) {
                        $handle = $candidate['handle'];
                    }
                }

                if ($handle === null && count($mentions) === 1) {
                    $handle = $mentions[0]['handle'];
                }

                $pairs[] = [
                    'figure' => $figure,
                    'handle' => $handle,
                    'format' => $this->formatNearOffset($text, $fullOffset),
                    'offset' => $fullOffset,
                ];
            }
        }

        // Ranges like 1.1-1.5x / 1.1x-1.5x quote both ends as times-usual claims.
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*[x×]?\s*[-–—]\s*(\d+(?:\.\d+)?)\s*[x×]/iu', $text, $ranges, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($ranges[0] as $index => $full) {
                $offset = (int) $full[1];
                $handle = null;

                foreach ($mentions as $candidate) {
                    if ($candidate['offset'] <= $offset) {
                        $handle = $candidate['handle'];
                    }
                }

                if ($handle === null && count($mentions) === 1) {
                    $handle = $mentions[0]['handle'];
                }

                $format = $this->formatNearOffset($text, $offset);

                foreach ([$ranges[1][$index][0], $ranges[2][$index][0]] as $raw) {
                    $pairs[] = [
                        'figure' => $this->normaliseNumber($raw),
                        'handle' => $handle,
                        'format' => $format,
                        'offset' => $offset,
                    ];
                }
            }
        }

        $unique = [];
        foreach ($pairs as $pair) {
            if ($pair['figure'] === '') {
                continue;
            }
            $key = $pair['figure'].'|'.($pair['handle'] ?? '').'|'.($pair['format'] ?? '');
            $unique[$key] = $pair;
        }

        return array_values($unique);
    }

    private function formatNearOffset(string $text, int $offset): ?string
    {
        // PREG_OFFSET_CAPTURE is byte-based; keep substr in the same space.
        $radius = 48;
        $start = max(0, $offset - $radius);
        $length = min(strlen($text) - $start, ($offset - $start) + $radius);
        $window = substr($text, $start, $length);

        if (preg_match_all('/\b(reels?|carousels?|images?|stories?)\b/iu', $window, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        $relativeOffset = $offset - $start;

        foreach ($matches[1] as $match) {
            $local = (int) $match[1];
            $distance = abs($local - $relativeOffset);
            if ($distance > $radius || $distance >= $bestDistance) {
                continue;
            }

            $bestDistance = $distance;
            $best = strtolower((string) $match[0]);
        }

        if ($best === null) {
            return null;
        }

        return match (true) {
            str_starts_with($best, 'reel') => 'Reel',
            str_starts_with($best, 'carousel') => 'Carousel',
            str_starts_with($best, 'image') => 'Image',
            str_starts_with($best, 'stor') => 'Story',
            default => null,
        };
    }

    /**
     * @param  list<string>  $allowedHandles
     * @param  array<string, mixed>  $facts
     * @return list<array{handle: string, offset: int}>
     */
    public function competitorMentionsInText(string $text, array $allowedHandles, array $facts = []): array
    {
        $wanted = array_values(array_unique(array_filter(array_map(
            fn (string $handle): string => $this->normaliseHandle($handle),
            $allowedHandles,
        ))));

        /** @var list<array{handle: string, alias: string}> $aliasRows */
        $aliasRows = [];

        foreach ($this->accountRowsFromFacts($facts) as $row) {
            $handle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
            if ($handle === '' || ($wanted !== [] && ! in_array($handle, $wanted, true))) {
                continue;
            }

            foreach ($this->aliasesForAccount($row) as $alias) {
                $aliasRows[] = ['handle' => $handle, 'alias' => $alias];
            }
        }

        foreach ($wanted as $handle) {
            $aliasRows[] = ['handle' => $handle, 'alias' => $handle];
            $aliasRows[] = ['handle' => $handle, 'alias' => '@'.$handle];

            $spaced = trim(preg_replace('/[._\-]+/u', ' ', $handle) ?? $handle);
            if ($spaced !== '' && $spaced !== $handle) {
                $aliasRows[] = ['handle' => $handle, 'alias' => $spaced];
            }
        }

        usort(
            $aliasRows,
            fn (array $a, array $b): int => mb_strlen($b['alias']) <=> mb_strlen($a['alias']),
        );

        $mentions = [];
        $seen = [];

        foreach ($aliasRows as $row) {
            $alias = trim($row['alias']);
            if ($alias === '') {
                continue;
            }

            $pattern = $this->aliasMatchPattern($alias);
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $offset = (int) $match[1];
                $key = $row['handle'].'@'.$offset;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $mentions[] = [
                    'handle' => $row['handle'],
                    'offset' => $offset,
                ];
            }
        }

        usort($mentions, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $mentions;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    public function accountRowsFromFacts(array $facts): array
    {
        $rows = [];

        if (is_array($facts['own'] ?? null)) {
            $rows[] = $facts['own'];
        }

        foreach ($facts['competitors'] ?? [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function aliasesForAccount(array $row): array
    {
        $handle = $this->normaliseHandle((string) ($row['handle'] ?? ''));
        $aliases = [];

        if ($handle !== '') {
            $aliases[] = $handle;
            $aliases[] = '@'.$handle;
            $spaced = trim(preg_replace('/[._\-]+/u', ' ', $handle) ?? $handle);
            if ($spaced !== '' && strcasecmp($spaced, $handle) !== 0) {
                $aliases[] = $spaced;
            }
        }

        $display = trim((string) ($row['display_name'] ?? ''));
        if ($display !== '') {
            $aliases[] = $display;

            // "One House Social Club" also matches prose that shortens to "One House".
            $parts = preg_split('/\s+/u', $display) ?: [];
            if (count($parts) >= 3) {
                $aliases[] = $parts[0].' '.$parts[1];
            }
        }

        return array_values(array_unique(array_filter(
            $aliases,
            fn (string $alias): bool => trim($alias) !== '',
        )));
    }

    private function aliasMatchPattern(string $alias): string
    {
        $trimmed = trim($alias);

        if (str_starts_with($trimmed, '@') || ! preg_match('/\s/u', $trimmed)) {
            $normalised = $this->normaliseHandle($trimmed);

            return '/(?<![A-Za-z0-9.])@?'.preg_quote($normalised, '/').'(?![A-Za-z0-9.])/iu';
        }

        $parts = preg_split('/\s+/u', $trimmed) ?: [];
        $escaped = array_map(
            fn (string $part): string => preg_quote($part, '/'),
            array_values(array_filter($parts, fn (string $part): bool => $part !== '')),
        );

        if ($escaped === []) {
            return '/(*FAIL)/';
        }

        return '/(?<![A-Za-z0-9])'.implode('\s+', $escaped).'(?![A-Za-z0-9])/iu';
    }

    /**
     * @param  list<string>  $allowed
     */
    public function numberIsKnownAsTimesUsual(string $number, array $allowed): bool
    {
        if ($this->numberIsKnown($number, $allowed)) {
            return true;
        }

        $value = (float) $this->normaliseNumber($number);

        foreach ($allowed as $token) {
            if (! is_numeric($token)) {
                continue;
            }

            // Accept normal 1-decimal rounding drift (2.25 cited as 2.3, etc.).
            if (abs($value - (float) $token) <= 0.051) {
                return true;
            }

            if (number_format($value, 1, '.', '') === number_format((float) $token, 1, '.', '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $handles
     */
    private function sevenDayReplacementFigure(array $facts, array $handles, string $claimed): ?string
    {
        unset($claimed);

        $candidates = [];

        foreach ($this->sevenDayPosts($facts, $handles) as $post) {
            foreach (['times_usual', 'views_vs_usual'] as $key) {
                if (! isset($post[$key]) || ! is_numeric($post[$key])) {
                    continue;
                }

                $candidates[] = (float) $post[$key];
            }
        }

        if ($candidates === []) {
            return null;
        }

        rsort($candidates);

        return number_format($candidates[0], 1, '.', '');
    }

    private function replaceTimesUsualFigure(string $text, string $from, string $to): string
    {
        $pattern = '/(?<!\d)'.preg_quote($from, '/').'(?=\s*(?:[x×]|times (?:their |the )?usual))/iu';

        return preg_replace($pattern, $to, $text, 1) ?? $text;
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
        $mentioned = $this->handlesMentionedIn($text, $this->allowedHandles($facts), $facts);
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
        return $this->timesUsualNumbersFromPosts($this->sevenDayPosts($facts, $handles));
    }

    /**
     * Multipliers for a handle from every post supplied in brief facts (7d, 24h, standouts, etc.).
     *
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $handles
     * @return list<string>
     */
    public function handleTimesUsualNumbers(array $facts, array $handles = []): array
    {
        return $this->timesUsualNumbersFromPosts($this->handlePosts($facts, $handles));
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     * @return list<string>
     */
    public function timesUsualNumbersFromPosts(array $posts): array
    {
        $tokens = [];

        foreach ($posts as $post) {
            foreach (['times_usual', 'views_vs_usual'] as $key) {
                if (! isset($post[$key]) || ! is_numeric($post[$key])) {
                    continue;
                }

                $tokens[] = $this->normaliseNumber((string) $post[$key]);
                $tokens[] = $this->normaliseNumber(number_format((float) $post[$key], 1, '.', ''));
                $tokens[] = $this->normaliseNumber(number_format((float) $post[$key], 2, '.', ''));
            }

            $label = (string) ($post['times_usual_label'] ?? '');
            if ($label !== '' && preg_match_all('/(\d+(?:\.\d+)?)\s*(?:[x×]|times (?:their |your |the )?usual)/iu', $label, $matches) > 0) {
                foreach ($matches[1] as $raw) {
                    $tokens[] = $this->normaliseNumber($raw);
                }
            }
        }

        return array_values(array_unique(array_filter(
            $tokens,
            fn (string $token): bool => $token !== '',
        )));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $handles
     * @return list<array<string, mixed>>
     */
    public function handlePosts(array $facts, array $handles = []): array
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

            foreach (['posts_last_7d', 'posts_last_24h', 'posts_yesterday', 'standout_winners', 'best_post_7d', 'best_post_30d'] as $key) {
                $value = $row[$key] ?? null;
                if (in_array($key, ['best_post_7d', 'best_post_30d'], true) && is_array($value) && isset($value['post_id'])) {
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

        foreach (['top_competitor_hit_7d', 'top_competitor_hit_24h', 'top_competitor_hit'] as $topKey) {
            $top = $facts[$topKey] ?? null;
            if (! is_array($top)) {
                continue;
            }

            $handle = $this->normaliseHandle((string) ($top['handle'] ?? ''));
            if ($wanted === [] || $handle === '' || in_array($handle, $wanted, true)) {
                $posts[] = $top;
            }
        }

        return $posts;
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

            foreach (['posts_last_7d', 'posts_last_24h', 'posts_yesterday', 'best_post_7d'] as $key) {
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

        foreach (['top_competitor_hit_7d', 'top_competitor_hit_24h'] as $topKey) {
            $top = $facts[$topKey] ?? null;
            if (! is_array($top)) {
                continue;
            }

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

            // Accounts with no posts (or empty sync) are not in the longest-gap race.
            if (($row['sync_empty'] ?? false)
                || (($row['last_posted_at'] ?? null) === null && ! is_numeric($row['days_since_last_post'] ?? null))) {
                continue;
            }

            $days = is_numeric($row['days_since_last_post'] ?? null) ? (int) $row['days_since_last_post'] : null;

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
            if (! isset($cadence['posts_last_7d']) && ! isset($cadence['posts_last_7d_count'])) {
                $cadence['posts_last_7d'] = (int) ($row['posts_last_7d_count'] ?? $row['posts_last_7d'] ?? 0);
            }

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
        // Soft cadence adjectives are handled by claimsAlmostDailyPosting first.
        if ($this->claimsAlmostDailyPosting($text)) {
            return false;
        }

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

        if (preg_match('/\b(?:daily[\s\-]?like|near[\s\-]?daily)\b/i', $text) === 1) {
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
     * Almost-daily / daily-like / near-daily: 6+ distinct days, or at least 5 posts in 7 days.
     *
     * @param  array<string, mixed>  $cadence
     */
    private function cadenceLooksAlmostDaily(array $cadence): bool
    {
        if (($cadence['posted_almost_daily_last_7'] ?? false) || ($cadence['posted_every_day_last_7'] ?? false)) {
            return true;
        }

        if ((int) ($cadence['distinct_days_posted_last_7'] ?? 0) >= 6) {
            return true;
        }

        $posts = (int) ($cadence['posts_last_7d'] ?? $cadence['posts_last_7d_count'] ?? 0);

        return $posts >= 5;
    }

    /**
     * @param  list<string>  $handles
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function handlesMentionedIn(string $text, array $handles, array $facts = []): array
    {
        return array_values(array_unique(array_map(
            fn (array $mention): string => $mention['handle'],
            $this->competitorMentionsInText($text, $handles, $facts),
        )));
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
     * Soften standout / hit / top praise when engagement is at or below the threshold.
     * Reject only if praise wording still remains after rewrite.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function rewriteUnsupportedStandoutClaims(array $output, array $facts): array
    {
        foreach (['headline', 'own_summary', 'competitor_summary'] as $field) {
            if (isset($output[$field]) && is_string($output[$field])) {
                $output[$field] = $this->rewriteUnsupportedStandoutClaimsInText($output[$field], $facts);
            }
        }

        if (isset($output['watch']) && is_array($output['watch'])) {
            $output['watch'] = array_map(
                fn (mixed $item): mixed => is_string($item)
                    ? $this->rewriteUnsupportedStandoutClaimsInText($item, $facts)
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
                        $action[$field] = $this->rewriteUnsupportedStandoutClaimsInText($action[$field], $facts);
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
    public function rewriteUnsupportedStandoutClaimsInText(string $text, array $facts): string
    {
        if ($text === '' || $this->unsupportedStandoutClaim($text, $facts) === null) {
            return $text;
        }

        $rewritten = $text;
        $rewritten = preg_replace('/\btop\s+hit\b/iu', '', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\btop\s+(post|reel|carousel|performer)\b/iu', '$1', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\b(?:biggest\s+)?hit\b/iu', '', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\bwas a standout\b/iu', '', $rewritten) ?? $rewritten;
        // Keep the article so "a standout Reel" becomes "a Reel", not "Reel".
        $rewritten = preg_replace('/\ba standout\b/iu', 'a', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\bstandout\b/iu', '', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\boutperformed\b/iu', 'posted', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\bbest\s+(post|reel|carousel|performer)\b/iu', 'recent $1', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\btheir best\b/iu', 'their recent', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\bbest recent\b/iu', 'recent', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\s{2,}/u', ' ', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/\s+([,.;:!?])/u', '$1', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/([.!?]){2,}/u', '$1', $rewritten) ?? $rewritten;

        return trim($rewritten);
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

        $handles = $this->handlesMentionedIn($text, $this->allowedHandles($facts), $facts);
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
            // A cited Nx next to standout/hit wording must itself clear the threshold,
            // even when another mentioned account has a stronger post elsewhere in the text.
            foreach ($this->figureHandlePairsInText($text, $this->allowedHandles($facts), $facts) as $pair) {
                if (! is_numeric($pair['figure'])) {
                    continue;
                }

                if ((float) $pair['figure'] > self::STANDOUT_THRESHOLD) {
                    continue;
                }

                if ($this->standoutWordNearOffset($text, (int) $pair['offset'])) {
                    return 'standout claim needs times-usual above '.self::STANDOUT_THRESHOLD;
                }
            }

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

    private function standoutWordNearOffset(string $text, int $offset): bool
    {
        $radius = 60;
        $start = max(0, $offset - $radius);
        $length = min(strlen($text) - $start, ($offset - $start) + $radius);
        $window = substr($text, $start, $length);

        return preg_match('/\b(standout|outperformed|(?:biggest\s+)?hit)\b|\btop\s+(?:post|reel|carousel|performer)\b/iu', $window) === 1;
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
