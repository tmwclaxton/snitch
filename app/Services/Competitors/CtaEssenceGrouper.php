<?php

namespace App\Services\Competitors;

use App\Services\Analysis\NanoGptClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CtaEssenceGrouper
{
    private const GROUP_LIMIT = 8;

    private const PHRASE_LIMIT = 40;

    private const CATCH_ALL = 'No clear ask';

    /** Fixed plain chip labels shown on the dashboard. */
    public const LABELS = [
        'Comment a keyword',
        'Tag a friend',
        'DM us',
        'Link in bio',
        'Save this',
        'Share this',
        'Join the event',
        'Ask a question',
        self::CATCH_ALL,
    ];

    public function __construct(private NanoGptClient $nanoGpt) {}

    /**
     * Collapse analysed CTA lines into short ask types.
     *
     * @param  array<string, int>  $counts
     * @return list<array{term: string, count: int, lines: list<array{text: string, count: int}>}>
     */
    public function group(array $counts): array
    {
        arsort($counts);
        $counts = array_slice($counts, 0, self::PHRASE_LIMIT, true);

        if ($counts === []) {
            return [];
        }

        $mapping = $this->cachedMapping($counts) ?? $this->fallbackMapping(array_keys($counts));

        return $this->hydrate($mapping, $counts);
    }

    /**
     * Map a free-text CTA label or phrase onto the fixed plain set.
     */
    public function canonicalLabel(string $label): string
    {
        $normalised = $this->normalise($label);

        if ($normalised === ''
            || $normalised === 'other'
            || $normalised === 'other asks'
            || $normalised === 'no clear ask'
            || $normalised === 'no explicit cta'
            || $normalised === 'no ask'
            || $normalised === 'none') {
            return self::CATCH_ALL;
        }

        foreach (self::LABELS as $canonical) {
            if ($this->normalise($canonical) === $normalised) {
                return $canonical;
            }
        }

        // Exact-ish aliases from older LLM/free-text labels.
        $aliases = [
            'comment a keyword' => 'Comment a keyword',
            'comment with keyword' => 'Comment a keyword',
            'comment to receive details' => 'Comment a keyword',
            'comment for the guide' => 'Comment a keyword',
            'comment guide' => 'Comment a keyword',
            'tag a friend' => 'Tag a friend',
            'comment which friend' => 'Tag a friend',
            'tag friend' => 'Tag a friend',
            'dm us' => 'DM us',
            'direct message for invite' => 'DM us',
            'dm or signup' => 'DM us',
            'dm' => 'DM us',
            'link in bio' => 'Link in bio',
            'grab ticket via bio' => 'Link in bio',
            'link in the bio' => 'Link in bio',
            'check out local resource' => 'Link in bio',
            'save this' => 'Save this',
            'save for later' => 'Save this',
            'share this' => 'Share this',
            'share with a friend' => 'Share this',
            'join the event' => 'Join the event',
            'join challenge or event' => 'Join the event',
            'get involved or join' => 'Join the event',
            'get involved in area' => 'Join the event',
            'get involved' => 'Join the event',
            'book a table' => 'Join the event',
            'come along' => 'Join the event',
            'ask a question' => 'Ask a question',
            'swipe to see content' => self::CATCH_ALL,
            'watch the full episode' => self::CATCH_ALL,
            'listen or watch' => self::CATCH_ALL,
        ];

        if (isset($aliases[$normalised])) {
            return $aliases[$normalised];
        }

        return $this->classifyByKeywords($normalised);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{label: string, phrases: list<string>}>|null
     */
    private function cachedMapping(array $counts): ?array
    {
        if (! $this->shouldCallModel()) {
            return null;
        }

        // Bump cache key when the fixed label set or phrase remaps change.
        $key = 'cta-essence:v3:'.hash('sha256', implode("\n", array_keys($counts)));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $this->validMapping($cached, $counts);
        }

        try {
            $mapping = $this->fromModel($counts);
        } catch (Throwable $exception) {
            Log::warning('CTA essence grouping failed', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($mapping === null) {
            return null;
        }

        Cache::put($key, $mapping, now()->addHours(12));

        return $mapping;
    }

    private function shouldCallModel(): bool
    {
        if (app()->runningUnitTests() && ! config('snitch.cta_essence.force')) {
            return false;
        }

        return (string) config('snitch.nanogpt.api_key') !== '';
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{label: string, phrases: list<string>}>|null
     */
    private function fromModel(array $counts): ?array
    {
        $allowed = implode(', ', array_map(fn (string $label): string => '"'.$label.'"', self::LABELS));

        $decoded = $this->nanoGpt->chatJson([
            [
                'role' => 'system',
                'content' => 'Group social post calls to action by the ask they make. Reply with JSON only: {"groups":[{"label":"exact label","phrases":["exact input"]}]}. Each label MUST be one of: '.$allowed.'. Copy phrases exactly from the input. Put every input phrase in exactly one group. Do not invent phrases. Prefer a real ask label when the phrase implies one (comment, tag, DM, bio link, save, share, join/attend/book, ask). Use "No clear ask" only for passive or non-asks (swipe, watch/listen with no action, vague "check this out" with no destination). Never use "Other".',
            ],
            [
                'role' => 'user',
                'content' => json_encode(array_keys($counts), JSON_UNESCAPED_UNICODE),
            ],
        ], (string) config('snitch.cta_essence.model', 'deepseek/deepseek-v4-flash'), [
            'temperature' => 0.1,
            'max_tokens' => 800,
            'timeout' => 12,
        ]);

        if (! is_array($decoded)) {
            return null;
        }

        return $this->validMapping($decoded['groups'] ?? null, $counts);
    }

    /**
     * @param  list<string>  $phrases
     * @return list<array{label: string, phrases: list<string>}>
     */
    private function fallbackMapping(array $phrases): array
    {
        $buckets = [];

        foreach ($phrases as $phrase) {
            $label = $this->canonicalLabel($phrase);
            $buckets[$label] ??= [];
            $buckets[$label][] = $phrase;
        }

        $groups = [];

        foreach (self::LABELS as $label) {
            if (! isset($buckets[$label])) {
                continue;
            }

            $groups[] = [
                'label' => $label,
                'phrases' => $buckets[$label],
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{label: string, phrases: list<string>}>|null
     */
    private function validMapping(mixed $groups, array $counts): ?array
    {
        if (! is_array($groups) || $groups === []) {
            return null;
        }

        $known = [];

        foreach (array_keys($counts) as $phrase) {
            $known[$this->normalise($phrase)] = $phrase;
        }

        $used = [];
        $buckets = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $groupLabel = $this->canonicalLabel((string) ($group['label'] ?? ''));

            foreach ($group['phrases'] ?? [] as $phrase) {
                if (! is_string($phrase)) {
                    continue;
                }

                $original = $known[$this->normalise($phrase)] ?? null;

                if ($original === null || isset($used[$original])) {
                    continue;
                }

                $used[$original] = true;
                $label = $this->resolvePhraseLabel($original, $groupLabel);
                $buckets[$label][] = $original;
            }
        }

        if ($buckets === []) {
            return null;
        }

        $leftover = array_values(array_diff(array_keys($counts), array_keys($used)));

        foreach ($leftover as $phrase) {
            $buckets[$this->canonicalLabel($phrase)][] = $phrase;
        }

        $mapping = [];

        foreach (self::LABELS as $label) {
            if (! isset($buckets[$label]) || $buckets[$label] === []) {
                continue;
            }

            $mapping[] = [
                'label' => $label,
                'phrases' => $buckets[$label],
            ];
        }

        return $mapping === [] ? null : $mapping;
    }

    /**
     * Prefer phrase keywords over a catch-all LLM group label.
     */
    private function resolvePhraseLabel(string $phrase, string $groupLabel): string
    {
        $phraseLabel = $this->canonicalLabel($phrase);

        if ($phraseLabel !== self::CATCH_ALL) {
            return $phraseLabel;
        }

        if ($groupLabel !== self::CATCH_ALL) {
            return $groupLabel;
        }

        return self::CATCH_ALL;
    }

    /**
     * @param  list<array{label: string, phrases: list<string>}>  $mapping
     * @param  array<string, int>  $counts
     * @return list<array{term: string, count: int, lines: list<array{text: string, count: int}>}>
     */
    private function hydrate(array $mapping, array $counts): array
    {
        $rows = [];

        foreach ($mapping as $group) {
            $lines = [];

            foreach ($group['phrases'] as $phrase) {
                $lines[] = [
                    'text' => $this->displayText($phrase),
                    'count' => (int) ($counts[$phrase] ?? 0),
                ];
            }

            usort($lines, fn (array $left, array $right): int => $right['count'] <=> $left['count']);

            $rows[] = [
                'term' => $group['label'],
                'count' => array_sum(array_column($lines, 'count')),
                'lines' => $lines,
            ];
        }

        usort($rows, function (array $left, array $right): int {
            if ($left['term'] === self::CATCH_ALL) {
                return 1;
            }

            if ($right['term'] === self::CATCH_ALL) {
                return -1;
            }

            return $right['count'] <=> $left['count'];
        });

        $head = array_slice($rows, 0, self::GROUP_LIMIT);
        $rest = array_slice($rows, self::GROUP_LIMIT);

        if ($rest === []) {
            return $head;
        }

        $extraLines = [];

        foreach ($rest as $row) {
            foreach ($row['lines'] as $line) {
                $extraLines[] = $line;
            }
        }

        $catchAll = null;

        foreach ($head as $index => $row) {
            if ($row['term'] === self::CATCH_ALL) {
                $catchAll = $index;
            }
        }

        if ($catchAll === null) {
            $head[] = [
                'term' => self::CATCH_ALL,
                'count' => array_sum(array_column($extraLines, 'count')),
                'lines' => $extraLines,
            ];
        } else {
            $head[$catchAll]['lines'] = [...$head[$catchAll]['lines'], ...$extraLines];
            $head[$catchAll]['count'] = array_sum(array_column($head[$catchAll]['lines'], 'count'));
        }

        return array_slice($head, 0, self::GROUP_LIMIT + 1);
    }

    private function classifyByKeywords(string $normalised): string
    {
        if (preg_match('/\b(tag|friend|tagging)\b/u', $normalised)) {
            return 'Tag a friend';
        }

        if (preg_match('/\b(dm|direct message|message us|inbox)\b/u', $normalised)) {
            return 'DM us';
        }

        if (preg_match('/\b(link in bio|bio link|in our bio|via bio|check out|visit|head to)\b/u', $normalised)) {
            return 'Link in bio';
        }

        if (preg_match('/\b(save|bookmark)\b/u', $normalised)) {
            return 'Save this';
        }

        if (preg_match('/\b(share|reshare|regram)\b/u', $normalised)) {
            return 'Share this';
        }

        if (preg_match('/\b(join|sign up|signup|register|rsvp|ticket|event|challenge|involved|volunteer|come along|attend|book)\b/u', $normalised)) {
            return 'Join the event';
        }

        if (preg_match('/\b(ask|question|\?)\b/u', $normalised) && ! preg_match('/\bcomment\b/u', $normalised)) {
            return 'Ask a question';
        }

        if (preg_match('/\b(comment|reply|drop|type|write)\b/u', $normalised)) {
            return 'Comment a keyword';
        }

        if (preg_match('/\b(swipe|listen|watch|podcast|episode|follow for more)\b/u', $normalised)) {
            return self::CATCH_ALL;
        }

        return self::CATCH_ALL;
    }

    private function displayText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private function normalise(string $phrase): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase));
    }
}
