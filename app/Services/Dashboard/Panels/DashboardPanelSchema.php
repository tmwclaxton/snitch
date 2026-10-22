<?php

namespace App\Services\Dashboard\Panels;

use App\Models\DashboardPanel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates curated dashboard panel definitions for set_panels / defaults.
 */
class DashboardPanelSchema
{
    /**
     * @param  list<array<string, mixed>>  $panels
     * @return list<array{position: int, type: string, title: string, payload: array<string, mixed>, source: string}>
     *
     * @throws ValidationException
     */
    public function validateMany(array $panels): array
    {
        $validator = Validator::make(
            ['panels' => $panels],
            [
                'panels' => ['required', 'array', 'max:40'],
                'panels.*.type' => ['required', 'string', Rule::in(DashboardPanel::types())],
                'panels.*.title' => ['required', 'string', 'max:120'],
                'panels.*.payload' => ['nullable', 'array'],
                'panels.*.source' => ['nullable', 'string', 'max:64'],
                'panels.*.position' => ['nullable', 'integer', 'min:0', 'max:999'],
            ],
        );

        $validated = $validator->validate();
        $rows = [];

        foreach (array_values($validated['panels']) as $index => $panel) {
            $type = (string) $panel['type'];
            $payload = is_array($panel['payload'] ?? null) ? $panel['payload'] : [];
            $this->validatePayload($type, $payload);

            $rows[] = [
                'position' => isset($panel['position']) ? (int) $panel['position'] : $index,
                'type' => $type,
                'title' => (string) $panel['title'],
                'payload' => $payload,
                'source' => (string) ($panel['source'] ?? 'curator'),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function validatePayload(string $type, array $payload): void
    {
        $rules = match ($type) {
            DashboardPanel::TYPE_BIG_NUMBER => [
                'label' => ['required', 'string', 'max:80'],
                'value' => ['required', 'string', 'max:80'],
                'note' => ['nullable', 'string', 'max:240'],
                'accent' => ['nullable', 'boolean'],
            ],
            DashboardPanel::TYPE_POST_CARD => [
                'post_id' => ['nullable', 'integer', 'min:1'],
                'handle' => ['required', 'string', 'max:100'],
                'format' => ['nullable', 'string', 'max:40'],
                'url' => ['nullable', 'string', 'max:500'],
                'posted_at' => ['nullable', 'string', 'max:40'],
                'caption' => ['nullable', 'string', 'max:500'],
                'likes' => ['nullable', 'integer', 'min:0'],
                'comments' => ['nullable', 'integer', 'min:0'],
                'views' => ['nullable', 'integer', 'min:0'],
                'times_usual_label' => ['nullable', 'string', 'max:80'],
                'thumbnail_url' => ['nullable', 'string', 'max:500'],
            ],
            DashboardPanel::TYPE_RANKED_LIST => [
                'items' => ['required', 'array', 'min:1', 'max:20'],
                'items.*.label' => ['required', 'string', 'max:160'],
                'items.*.value' => ['required', 'string', 'max:80'],
                'items.*.href' => ['nullable', 'string', 'max:500'],
            ],
            DashboardPanel::TYPE_LINE_CHART, DashboardPanel::TYPE_BAR_CHART => [
                'series' => ['required', 'array', 'min:1', 'max:8'],
                'series.*.name' => ['required', 'string', 'max:80'],
                'series.*.points' => ['required', 'array', 'min:1', 'max:120'],
                'series.*.points.*.x' => ['required'],
                'series.*.points.*.y' => ['nullable', 'numeric'],
                'y_label' => ['nullable', 'string', 'max:80'],
            ],
            DashboardPanel::TYPE_NOTE => [
                'body' => ['required', 'string', 'max:500'],
                'action_label' => ['nullable', 'string', 'max:80'],
                'action_href' => ['nullable', 'string', 'max:500'],
            ],
            default => throw ValidationException::withMessages([
                'type' => 'Unknown panel type.',
            ]),
        };

        Validator::make($payload, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $panel  Render-ready panel (type + payload)
     */
    public function hasData(array $panel): bool
    {
        $type = (string) ($panel['type'] ?? '');
        $payload = is_array($panel['payload'] ?? null) ? $panel['payload'] : [];

        return match ($type) {
            DashboardPanel::TYPE_BIG_NUMBER => filled($payload['value'] ?? null)
                && (string) $payload['value'] !== 'n/a'
                && (string) $payload['value'] !== '-',
            DashboardPanel::TYPE_POST_CARD => filled($payload['handle'] ?? null)
                && (filled($payload['post_id'] ?? null) || filled($payload['url'] ?? null) || filled($payload['caption'] ?? null)),
            DashboardPanel::TYPE_RANKED_LIST => is_array($payload['items'] ?? null)
                && count($payload['items']) > 0,
            DashboardPanel::TYPE_LINE_CHART, DashboardPanel::TYPE_BAR_CHART => $this->chartHasPoints($payload),
            DashboardPanel::TYPE_NOTE => filled($payload['body'] ?? null),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function chartHasPoints(array $payload): bool
    {
        $series = $payload['series'] ?? null;
        if (! is_array($series) || $series === []) {
            return false;
        }

        foreach ($series as $row) {
            if (! is_array($row)) {
                continue;
            }
            $points = $row['points'] ?? null;
            if (! is_array($points) || $points === []) {
                continue;
            }
            foreach ($points as $point) {
                if (is_array($point) && isset($point['y']) && is_numeric($point['y'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
