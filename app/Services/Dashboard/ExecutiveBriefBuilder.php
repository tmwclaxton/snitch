<?php

namespace App\Services\Dashboard;

/**
 * One-line board-slide headlines and takeaways for the executive dashboard.
 *
 * @phpstan-type Takeaway array{metric: string, text: string}
 * @phpstan-type SectionBrief array{headline: string, takeaways?: list<Takeaway>}
 */
class ExecutiveBriefBuilder
{
    /**
     * @param  array<string, mixed>|null  $ownRow
     * @param  array<string, mixed>  $kpis
     * @param  array<string, mixed>  $insights
     * @param  array<string, mixed>  $formatLift
     * @param  array<string, mixed>|null  $activity
     * @param  list<array{label: string, score: float}>|null  $bestTimes
     * @param  array{running_ads: int, recommendation: string}|null  $adsPanel
     * @return array{
     *     what_to_post: SectionBrief,
     *     performance: SectionBrief,
     *     ads: SectionBrief
     * }
     */
    public function build(
        ?array $ownRow,
        array $kpis,
        array $insights,
        array $formatLift,
        ?array $activity,
        ?array $bestTimes,
        ?array $adsPanel,
    ): array {
        return [
            'what_to_post' => [
                'headline' => $this->whatToPostHeadline($formatLift, $bestTimes, $activity, $ownRow),
                'takeaways' => $this->takeaways($insights),
            ],
            'performance' => [
                'headline' => $this->performanceHeadline($kpis, $ownRow),
            ],
            'ads' => [
                'headline' => $this->adsHeadline($adsPanel),
            ],
        ];
    }

    /**
     * @return array{
     *     what_to_post: SectionBrief,
     *     performance: SectionBrief,
     *     ads: SectionBrief
     * }
     */
    public function empty(): array
    {
        return [
            'what_to_post' => [
                'headline' => 'Add rivals to see what to post next.',
                'takeaways' => [],
            ],
            'performance' => [
                'headline' => 'Add rivals to see how you compare.',
            ],
            'ads' => [
                'headline' => 'None of your rivals run ads.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $formatLift
     * @param  list<array{label: string, score: float}>|null  $bestTimes
     * @param  array<string, mixed>|null  $activity
     * @param  array<string, mixed>|null  $ownRow
     */
    private function whatToPostHeadline(array $formatLift, ?array $bestTimes, ?array $activity, ?array $ownRow): string
    {
        $format = $this->bestFormat($formatLift, $ownRow);
        $slot = $this->bestSlot($bestTimes, $activity);

        if ($format !== null && $slot !== null) {
            return sprintf(
                'Post %s on %s: they get %s× the usual.',
                $this->pluralFormat($format['label']),
                $slot,
                $this->x($format['lift']),
            );
        }

        if ($format !== null) {
            return sprintf(
                'Lean into %s: they get %s× the usual.',
                $this->pluralFormat($format['label']),
                $this->x($format['lift']),
            );
        }

        if ($slot !== null) {
            $preposition = preg_match('/^\d{1,2}(am|pm)$/i', $slot) === 1 ? 'around' : 'on';

            return sprintf('Post next %s %s. That slot leads your rivals.', $preposition, $slot);
        }

        return 'Sync more posts to get a clear posting plan.';
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @param  array<string, mixed>|null  $ownRow
     */
    private function performanceHeadline(array $kpis, ?array $ownRow): string
    {
        $cards = collect($kpis['data']['cards'] ?? []);
        $er = $cards->firstWhere('key', 'er');
        $ppw = $cards->firstWhere('key', 'posts_per_week');

        $youEr = is_array($er) && is_numeric($er['you'] ?? null) ? (float) $er['you'] : null;
        $peerEr = is_array($er) && is_numeric($er['peer_median'] ?? null) ? (float) $er['peer_median'] : null;
        $youPpw = is_array($ppw) && is_numeric($ppw['you'] ?? null) ? (float) $ppw['you'] : null;
        $peerPpw = is_array($ppw) && is_numeric($ppw['peer_median'] ?? null) ? (float) $ppw['peer_median'] : null;

        if ($youEr !== null && $peerEr !== null && $peerEr > 0) {
            $erPart = $youEr >= $peerEr
                ? sprintf("You're winning on engagement (%s%% vs rivals' %s%%)", $this->pct($youEr), $this->pct($peerEr))
                : sprintf('Engagement trails rivals (%s%% vs %s%%)', $this->pct($youEr), $this->pct($peerEr));

            if ($youPpw !== null && $peerPpw !== null && $peerPpw > 0) {
                $ratio = $youPpw / $peerPpw;

                if ($ratio < 0.75) {
                    return $erPart.' but posting less often.';
                }

                if ($ratio > 1.25) {
                    return $erPart.' and posting more often.';
                }
            }

            return $erPart.'.';
        }

        if ($youPpw !== null && $peerPpw !== null && $peerPpw > 0) {
            $youCount = $this->countLabel($youPpw);
            $peerCount = $this->countLabel($peerPpw);

            if ($youPpw < $peerPpw) {
                return sprintf(
                    'You post %s a week; rivals post %s. Room to publish more.',
                    $youCount,
                    $peerCount,
                );
            }

            return sprintf(
                'You post %s a week; rivals post %s.',
                $youCount,
                $peerCount,
            );
        }

        if (is_array($ownRow) && ($ownRow['posts_n'] ?? 0) > 0) {
            return 'Keep tracking. Enough posts to start comparing.';
        }

        return 'Add rivals to see how you compare.';
    }

    /**
     * @param  array{running_ads?: int, recommendation?: string}|null  $adsPanel
     */
    private function adsHeadline(?array $adsPanel): string
    {
        $running = (int) ($adsPanel['running_ads'] ?? 0);

        if ($running === 0) {
            return 'None of your rivals run ads.';
        }

        $recommendation = trim((string) ($adsPanel['recommendation'] ?? ''));

        if ($recommendation !== '') {
            return rtrim($recommendation, '.').'.';
        }

        return sprintf('Rivals are running %d ad%s.', $running, $running === 1 ? '' : 's');
    }

    /**
     * @param  array<string, mixed>  $insights
     * @return list<Takeaway>
     */
    private function takeaways(array $insights): array
    {
        $items = $insights['data']['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $out = [];

        foreach (array_slice($items, 0, 3) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $plain = $this->stripMarkdown((string) ($item['text'] ?? ''));

            if ($plain === '') {
                continue;
            }

            $metric = filled($item['metric'] ?? null)
                ? (string) $item['metric']
                : ($this->extractMetric($plain) ?? $this->x((float) ($item['score'] ?? 0)).'×');
            $text = $this->takeawayLine($plain, $metric);

            if ($text === '' || $this->takeawayIncomplete($text)) {
                continue;
            }

            $out[] = [
                'metric' => $metric,
                'text' => $text,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $formatLift
     * @param  array<string, mixed>|null  $ownRow
     * @return array{label: string, lift: float}|null
     */
    private function bestFormat(array $formatLift, ?array $ownRow): ?array
    {
        $peerMedian = $formatLift['data']['peer_median_lift'] ?? [];

        if (is_array($peerMedian) && $peerMedian !== []) {
            $bestLabel = null;
            $bestLift = 0.0;

            foreach ($peerMedian as $format => $lift) {
                if (! is_numeric($lift)) {
                    continue;
                }

                $value = (float) $lift;

                if ($value > $bestLift) {
                    $bestLift = $value;
                    $bestLabel = (string) $format;
                }
            }

            if ($bestLabel !== null && $bestLift >= 1.1) {
                return ['label' => $bestLabel, 'lift' => $bestLift];
            }
        }

        $ownLifts = is_array($ownRow) ? ($ownRow['format_lift'] ?? []) : [];

        if (! is_array($ownLifts) || $ownLifts === []) {
            return null;
        }

        $bestLabel = null;
        $bestLift = 0.0;

        foreach ($ownLifts as $format => $lift) {
            if (! is_numeric($lift)) {
                continue;
            }

            $value = (float) $lift;

            if ($value > $bestLift) {
                $bestLift = $value;
                $bestLabel = (string) $format;
            }
        }

        if ($bestLabel === null || $bestLift < 1.1) {
            return null;
        }

        return ['label' => $bestLabel, 'lift' => $bestLift];
    }

    /**
     * @param  list<array{label: string, score?: float}>|null  $bestTimes
     * @param  array<string, mixed>|null  $activity
     */
    private function bestSlot(?array $bestTimes, ?array $activity): ?string
    {
        if (is_array($bestTimes) && $bestTimes !== []) {
            $label = trim((string) ($bestTimes[0]['label'] ?? ''));

            if ($label !== '') {
                return $this->humanSlot($label);
            }
        }

        $hours = $activity['by_time_of_day'] ?? [];

        if (! is_array($hours) || $hours === []) {
            return null;
        }

        usort($hours, static fn (array $a, array $b): int => ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0)));
        $label = trim((string) ($hours[0]['label'] ?? ''));

        return $label !== '' ? $this->humanSlot($label) : null;
    }

    private function humanSlot(string $label): string
    {
        // "Mon 20:00" / "Monday 20:00"
        if (preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s+(\d{1,2}):(\d{2})$/i', $label, $m) === 1) {
            $day = $this->fullDayName($m[1]);

            return "{$day} at ".$this->hour12((int) $m[2]);
        }

        // "9am" / "8pm"
        if (preg_match('/^(\d{1,2})\s*(am|pm)$/i', $label, $m) === 1) {
            return strtolower($m[1].$m[2]);
        }

        return $label;
    }

    private function fullDayName(string $day): string
    {
        return match (strtolower($day)) {
            'mon', 'monday' => 'Monday',
            'tue', 'tuesday' => 'Tuesday',
            'wed', 'wednesday' => 'Wednesday',
            'thu', 'thursday' => 'Thursday',
            'fri', 'friday' => 'Friday',
            'sat', 'saturday' => 'Saturday',
            'sun', 'sunday' => 'Sunday',
            default => $day,
        };
    }

    private function hour12(int $hour): string
    {
        $hour = (($hour % 24) + 24) % 24;

        if ($hour === 0) {
            return '12am';
        }

        if ($hour === 12) {
            return '12pm';
        }

        return $hour < 12 ? $hour.'am' : ($hour - 12).'pm';
    }

    private function pluralFormat(string $label): string
    {
        $base = strtolower(trim($label));

        if ($base === '') {
            return 'post';
        }

        if (str_ends_with($base, 's')) {
            return $base;
        }

        return $base.'s';
    }

    private function stripMarkdown(string $text): string
    {
        $plain = preg_replace('/\*\*(.+?)\*\*/', '$1', $text) ?? $text;
        $plain = str_replace(['*', '_'], '', $plain);

        return trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
    }

    private function extractMetric(string $plain): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*×/', $plain, $m) === 1) {
            return $m[1].'×';
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $plain, $m) === 1) {
            return $m[1].'%';
        }

        return null;
    }

    private function takeawayLine(string $plain, string $metric): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
        $multiplierCount = preg_match_all('/\d+(?:\.\d+)?\s*[×xX]/u', $line);

        // Only strip the tile metric when it is the sole × in the sentence.
        // Comparison lines keep every × so "Reels do 0.9×, carousels do 1.8×" stays intact.
        if ($multiplierCount === 1) {
            $metricPattern = preg_quote(rtrim($metric, '×xX'), '/');
            $line = trim(preg_replace('/'.$metricPattern.'\s*[×xX]/u', '', $line, 1) ?? $line);
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? $line);
            $line = preg_replace('/\bgot\s+their usual\b/iu', 'beat their usual', $line) ?? $line;
        }

        $line = trim($line, " \t\"'");

        if ($line === '') {
            return '';
        }

        if (mb_strlen($line) <= 96) {
            return $this->finishSentence($line);
        }

        if (preg_match('/^(.{24,96}?)[:.](?:\s|$)/u', $line, $match) === 1) {
            return $this->finishSentence(rtrim($match[1], ' .'));
        }

        $slice = mb_substr($line, 0, 96);
        $lastSpace = mb_strrpos($slice, ' ');

        if ($lastSpace !== false && $lastSpace > 28) {
            return $this->finishSentence(rtrim(mb_substr($slice, 0, $lastSpace), '.,;:-"\''));
        }

        return $this->finishSentence(rtrim($slice, '.,;:-"\''));
    }

    private function takeawayIncomplete(string $text): bool
    {
        // e.g. "Reels do . carousels do ." after a blank interpolation
        if (preg_match('/\bdo\s*\./u', $text) === 1) {
            return true;
        }

        if (preg_match('/\s\.\s*[a-z]/u', $text) === 1) {
            return true;
        }

        return preg_match('/\s\.\s*\./u', $text) === 1;
    }

    private function finishSentence(string $line): string
    {
        $line = trim($line, " \t\"'");

        if ($line === '') {
            return '';
        }

        if (! str_ends_with($line, '.') && ! str_ends_with($line, '!')) {
            return $line.'.';
        }

        return $line;
    }

    private function x(float $value): string
    {
        return number_format($value, $value >= 10 ? 0 : 1);
    }

    private function countLabel(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') ?: '0';
    }

    private function pct(float $value): string
    {
        return number_format($value, $value >= 10 ? 0 : 1);
    }
}
