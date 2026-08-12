<?php

namespace App\Services\Admin;

use Illuminate\Support\Carbon;

class AdminPeriodRange
{
    public function __construct(
        public string $grain,
        public int $periodCount,
        public Carbon $from,
        public Carbon $to,
    ) {}

    public static function resolve(string $grain = 'day', ?int $periods = null): self
    {
        $grain = in_array($grain, ['day', 'week', 'month'], true) ? $grain : 'day';

        $periodCount = match ($grain) {
            'week' => max(4, min(26, $periods ?? 12)),
            'month' => max(3, min(24, $periods ?? 12)),
            default => max(7, min(90, $periods ?? 30)),
        };

        $to = Carbon::parse(now()->endOfDay());
        $from = match ($grain) {
            'week' => Carbon::parse(now()->startOfWeek()->subWeeks($periodCount - 1)->startOfDay()),
            'month' => Carbon::parse(now()->startOfMonth()->subMonthsNoOverflow($periodCount - 1)->startOfDay()),
            default => Carbon::parse(now()->subDays($periodCount - 1)->startOfDay()),
        };

        return new self($grain, $periodCount, $from, $to);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * @return array{grain: string, period_count: int, days: int, from: string, to: string}
     */
    public function meta(): array
    {
        return [
            'grain' => $this->grain,
            'period_count' => $this->periodCount,
            'days' => $this->days(),
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }

    /**
     * @return array<string, array{date: string, label: string, count: int}>
     */
    public function emptyCountBuckets(): array
    {
        $buckets = [];

        for ($offset = 0; $offset < $this->periodCount; $offset++) {
            $cursor = match ($this->grain) {
                'week' => $this->from->copy()->addWeeks($offset)->startOfWeek(),
                'month' => $this->from->copy()->addMonthsNoOverflow($offset)->startOfMonth(),
                default => $this->from->copy()->addDays($offset)->startOfDay(),
            };
            $key = $cursor->toDateString();
            $buckets[$key] = [
                'date' => $key,
                'label' => match ($this->grain) {
                    'week' => $cursor->format('j M'),
                    'month' => $cursor->format('M Y'),
                    default => $cursor->format('j M'),
                },
                'count' => 0,
            ];
        }

        return $buckets;
    }

    public function bucketKey(\DateTimeInterface $at): string
    {
        $carbon = Carbon::parse($at);

        return match ($this->grain) {
            'week' => $carbon->copy()->startOfWeek()->toDateString(),
            'month' => $carbon->copy()->startOfMonth()->toDateString(),
            default => $carbon->toDateString(),
        };
    }

    /**
     * @param  array<string, array{date: string, label: string, count: int}>  $buckets
     * @return list<array{week_start: string, label: string, count: int}>
     */
    public function countSeries(array $buckets): array
    {
        return array_values(array_map(fn (array $bucket): array => [
            'week_start' => $bucket['date'],
            'label' => $bucket['label'],
            'count' => $bucket['count'],
        ], $buckets));
    }
}
