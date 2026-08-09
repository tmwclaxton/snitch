<?php

namespace App\Services\Dashboard;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DashboardActivityBuilder
{
    public const HEATMAP_WEEKS = 16;

    public const WEEKLY_WEEKS = 12;

    public function __construct(private PlanEntitlementService $entitlements) {}

    /**
     * @return array{
     *     heatmap: list<array{date: string, count: int}>,
     *     weekly: list<array{week_start: string, label: string, count: int}>,
     *     by_platform: list<array{platform: string, count: int}>,
     *     by_time_of_day: list<array{hour: int, label: string, count: int}>
     * }
     */
    public function forUser(User $user): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $heatmapEnd = $today;
        $heatmapStart = $this->sundayOnOrBefore($heatmapEnd)->subWeeks(self::HEATMAP_WEEKS - 1);
        $weeklyStart = $this->sundayOnOrBefore($heatmapEnd)->subWeeks(self::WEEKLY_WEEKS - 1);

        $postsQuery = Post::query()
            ->where('user_id', $user->id)
            ->reelLike()
            ->where('posted_at', '>=', $heatmapStart)
            ->whereNotNull('posted_at');

        $this->entitlements->constrainPostsToInQuotaAccounts($postsQuery, $user);

        $posts = $postsQuery->get(['posted_at', 'platform', 'tracked_account_id']);

        $dailyCounts = $this->dailyCounts($posts);
        $heatmap = $this->buildHeatmap($heatmapStart, $heatmapEnd, $dailyCounts);
        $weekly = $this->buildWeekly($weeklyStart, $heatmapEnd, $dailyCounts);
        $byPlatform = $this->buildByPlatform($posts, $weeklyStart);
        $byTimeOfDay = $this->buildByTimeOfDay($posts, $weeklyStart);

        return [
            'heatmap' => $heatmap,
            'weekly' => $weekly,
            'by_platform' => $byPlatform,
            'by_time_of_day' => $byTimeOfDay,
        ];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array<string, int>
     */
    private function dailyCounts(Collection $posts): array
    {
        $counts = [];

        foreach ($posts as $post) {
            if ($post->posted_at === null) {
                continue;
            }

            $date = $post->posted_at->toDateString();
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $dailyCounts
     * @return list<array{date: string, count: int}>
     */
    private function buildHeatmap(CarbonImmutable $start, CarbonImmutable $end, array $dailyCounts): array
    {
        $days = [];
        $cursor = $start;

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $days[] = [
                'date' => $key,
                'count' => $dailyCounts[$key] ?? 0,
            ];
            $cursor = $cursor->addDay();
        }

        $trailingPad = (6 - (int) $end->dayOfWeek + 7) % 7;

        for ($i = 1; $i <= $trailingPad; $i++) {
            $key = $end->addDays($i)->toDateString();
            $days[] = [
                'date' => $key,
                'count' => 0,
            ];
        }

        return $days;
    }

    /**
     * @param  array<string, int>  $dailyCounts
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function buildWeekly(CarbonImmutable $start, CarbonImmutable $end, array $dailyCounts): array
    {
        $weeks = [];
        $cursor = $start;

        for ($i = 0; $i < self::WEEKLY_WEEKS; $i++) {
            $weekStart = $cursor;
            $weekEnd = $cursor->addDays(6)->min($end);
            $count = 0;
            $day = $weekStart;

            while ($day->lte($weekEnd)) {
                $count += $dailyCounts[$day->toDateString()] ?? 0;
                $day = $day->addDay();
            }

            $weeks[] = [
                'week_start' => $weekStart->toDateString(),
                'label' => $weekStart->format('M j'),
                'count' => $count,
            ];

            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array{platform: string, count: int}>
     */
    private function buildByPlatform(Collection $posts, CarbonImmutable $weeklyStart): array
    {
        $counts = [];

        foreach ($posts as $post) {
            if ($post->posted_at === null || $post->posted_at->lt($weeklyStart)) {
                continue;
            }

            $platform = $post->platform instanceof Platform
                ? $post->platform->value
                : (string) $post->platform;

            $counts[$platform] = ($counts[$platform] ?? 0) + 1;
        }

        arsort($counts);

        $result = [];

        foreach ($counts as $platform => $count) {
            $result[] = [
                'platform' => $platform,
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array{hour: int, label: string, count: int}>
     */
    private function buildByTimeOfDay(Collection $posts, CarbonImmutable $weeklyStart): array
    {
        $counts = array_fill(0, 24, 0);

        foreach ($posts as $post) {
            if ($post->posted_at === null || $post->posted_at->lt($weeklyStart)) {
                continue;
            }

            $hour = (int) $post->posted_at->format('G');
            $counts[$hour]++;
        }

        $result = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $result[] = [
                'hour' => $hour,
                'label' => $this->hourLabel($hour),
                'count' => $counts[$hour],
            ];
        }

        return $result;
    }

    private function hourLabel(int $hour): string
    {
        if ($hour === 0) {
            return '12a';
        }

        if ($hour < 12) {
            return $hour.'a';
        }

        if ($hour === 12) {
            return '12p';
        }

        return ($hour - 12).'p';
    }

    private function sundayOnOrBefore(CarbonImmutable $day): CarbonImmutable
    {
        return $day->subDays((int) $day->dayOfWeek)->startOfDay();
    }
}
