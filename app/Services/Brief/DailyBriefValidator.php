<?php

namespace App\Services\Brief;

use Carbon\CarbonImmutable;

class DailyBriefValidator
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return array{ok: bool, errors: list<string>, output: array<string, mixed>}
     */
    public function validate(array $output, array $facts): array
    {
        $output = $this->replaceDashes($output);
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
                $kept[] = $action;
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
}
