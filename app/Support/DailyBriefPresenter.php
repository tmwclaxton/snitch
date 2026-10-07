<?php

namespace App\Support;

use App\Mcp\Support\McpAppUrls;
use App\Models\DailyBrief;
use App\Services\Brief\DailyBriefCopySanitizer;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;

class DailyBriefPresenter
{
    public function __construct(
        private DailyBriefCopySanitizer $copySanitizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(DailyBrief $brief): array
    {
        $this->copySanitizer->sanitizeStored($brief);
        $brief->refresh();

        $payload = is_array($brief->payload) ? $brief->payload : [];
        $payload = $this->normalizeChangeSinceYesterday($payload, $brief);

        return [
            ...$payload,
            'id' => (int) $brief->id,
            'brief_date' => $brief->brief_date?->toDateString(),
            'status' => $brief->status,
            'headline' => $brief->headline,
            'model' => $brief->model,
            'llm_attempts' => (int) $brief->llm_attempts,
            'was_free' => (bool) $brief->was_free,
            'credits_charged_pence' => (float) $brief->credits_charged_pence,
            'generated_at' => $brief->generated_at?->timezone(DashboardMath::TIMEZONE)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toPlainText(array $payload): string
    {
        $lines = [];
        $date = (string) ($payload['brief_date'] ?? '');
        $lines[] = 'Daily summary'.($date !== '' ? ' for '.$date : '');
        $lines[] = '';
        $lines[] = 'Headline';
        $lines[] = (string) ($payload['headline'] ?? '');
        $lines[] = '';
        $lines[] = "Today's numbers";

        foreach ($payload['big_numbers'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $note = filled($row['note'] ?? null) ? ' ('.$row['note'].')' : '';
            $lines[] = '- '.($row['label'] ?? '').': '.($row['value'] ?? '').$note;
        }

        $lines[] = '';
        $lines[] = 'Action points';

        foreach (array_values($payload['actions'] ?? []) as $index => $action) {
            if (! is_array($action)) {
                continue;
            }
            $done = filled($action['done_at'] ?? null) ? '[x]' : '[ ]';
            $lines[] = ($index + 1).'. '.$done.' '.($action['title'] ?? '');
            if (filled($action['how'] ?? null)) {
                $lines[] = '   What: '.$action['how'];
            }
            if (filled($action['why'] ?? null)) {
                $lines[] = '   Why: '.$action['why'];
            }
            if (filled($action['when'] ?? null)) {
                $lines[] = '   When: '.$action['when'];
            }
            foreach ($action['inspiration'] ?? [] as $link) {
                if (is_array($link) && filled($link['url'] ?? null)) {
                    $lines[] = '   Inspiration: '.$link['url'];
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Your account: last 7 days';
        foreach ($payload['own_last_7_days'] ?? [] as $post) {
            if (! is_array($post)) {
                continue;
            }
            $lines[] = '- '.($post['posted_label'] ?? '').', '.($post['format'] ?? '').' "'.($post['hook'] ?? $post['caption'] ?? '').'": '.($post['times_usual_label'] ?? '');
        }

        $lines[] = '';
        $lines[] = 'What competitors did';
        foreach ($payload['competitor_moves'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = '- @'.($row['handle'] ?? '').': '.((int) ($row['posts_last_7d_count'] ?? 0)).' posts in 7 days';
        }

        $lines[] = '';
        $lines[] = 'Things to watch';
        foreach ($payload['watch'] ?? [] as $item) {
            $lines[] = '- '.$item;
        }

        return implode("\n", $lines);
    }

    public static function appUrl(?string $date = null): string
    {
        return McpAppUrls::today($date);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeChangeSinceYesterday(array $payload, DailyBrief $brief): array
    {
        $numbers = $payload['big_numbers'] ?? [];

        if (! is_array($numbers)) {
            return $payload;
        }

        $started = $brief->brief_date?->timezone(DashboardMath::TIMEZONE)
            ?? CarbonImmutable::now(DashboardMath::TIMEZONE);
        $note = sprintf(
            'Daily tracking started %s; first comparison tomorrow',
            $started->format('j M'),
        );

        foreach ($numbers as $index => $row) {
            if (! is_array($row) || ($row['label'] ?? '') !== 'Change since yesterday') {
                continue;
            }

            $value = trim((string) ($row['value'] ?? ''));
            $existingNote = trim((string) ($row['note'] ?? ''));
            $legacy = $value === 'Daily tracking starts today'
                || $existingNote === 'Daily tracking starts today'
                || str_contains($value, 'Daily tracking starts');

            if (! $legacy) {
                continue;
            }

            $numbers[$index]['value'] = 'New';
            $numbers[$index]['note'] = $note;
        }

        $payload['big_numbers'] = array_values($numbers);

        return $payload;
    }
}
