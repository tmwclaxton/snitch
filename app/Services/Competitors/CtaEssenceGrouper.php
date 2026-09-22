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
     * @param  array<string, int>  $counts
     * @return list<array{label: string, phrases: list<string>}>|null
     */
    private function cachedMapping(array $counts): ?array
    {
        if (! $this->shouldCallModel()) {
            return null;
        }

        $key = 'cta-essence:'.hash('sha256', implode("\n", array_keys($counts)));
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
        $decoded = $this->nanoGpt->chatJson([
            [
                'role' => 'system',
                'content' => 'Group social post calls to action by the ask they make. Reply with JSON only: {"groups":[{"label":"short ask","phrases":["exact input"]}]}. Each label is 2 to 6 words in UK English sentence case and names the ask, not a quote. Copy phrases exactly from the input. Put every input phrase in exactly one group. Do not invent phrases.',
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
        $groups = [];

        foreach ($phrases as $phrase) {
            $groups[] = [
                'label' => $this->shortLabel($phrase),
                'phrases' => [$phrase],
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
        $mapping = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $label = $this->cleanLabel((string) ($group['label'] ?? ''));
            $phrases = [];

            foreach ($group['phrases'] ?? [] as $phrase) {
                if (! is_string($phrase)) {
                    continue;
                }

                $original = $known[$this->normalise($phrase)] ?? null;

                if ($original === null || isset($used[$original])) {
                    continue;
                }

                $used[$original] = true;
                $phrases[] = $original;
            }

            if ($label === '' || $phrases === []) {
                continue;
            }

            $mapping[] = [
                'label' => $label,
                'phrases' => $phrases,
            ];
        }

        if ($mapping === []) {
            return null;
        }

        $leftover = array_values(array_diff(array_keys($counts), array_keys($used)));

        if ($leftover !== []) {
            $mapping[] = [
                'label' => 'Other asks',
                'phrases' => $leftover,
            ];
        }

        return $mapping;
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
            if ($left['term'] === 'Other asks') {
                return 1;
            }

            if ($right['term'] === 'Other asks') {
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
            if ($row['term'] === 'Other asks') {
                $extraLines = [...$extraLines, ...$row['lines']];

                continue;
            }

            foreach ($row['lines'] as $line) {
                $extraLines[] = $line;
            }
        }

        $other = null;

        foreach ($head as $index => $row) {
            if ($row['term'] === 'Other asks') {
                $other = $index;
            }
        }

        if ($other === null) {
            $head[] = [
                'term' => 'Other asks',
                'count' => array_sum(array_column($extraLines, 'count')),
                'lines' => $extraLines,
            ];
        } else {
            $head[$other]['lines'] = [...$head[$other]['lines'], ...$extraLines];
            $head[$other]['count'] = array_sum(array_column($head[$other]['lines'], 'count'));
        }

        return array_slice($head, 0, self::GROUP_LIMIT + 1);
    }

    private function cleanLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        $label = trim($label, " \t\n\r\0\x0B\"'");

        if ($label === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $label) ?: [];
        $label = implode(' ', array_slice($words, 0, 6));

        if (mb_strlen($label) > 48) {
            $label = mb_substr($label, 0, 48);
            $label = preg_replace('/\s+\S*$/u', '', $label) ?? $label;
        }

        return $this->displayText($label);
    }

    private function shortLabel(string $phrase): string
    {
        $words = preg_split('/\s+/u', trim($phrase)) ?: [];

        return $this->displayText(implode(' ', array_slice($words, 0, 6)));
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
