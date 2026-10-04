<?php

namespace Tests\Feature\Growth;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GrowthPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_growth_page_shows_snapshot_note_with_single_point(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => true,
            'handle' => 'goodgym',
            'followers' => 1000,
        ]);

        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 1000,
            'captured_on' => CarbonImmutable::now()->subDays(3)->toDateString(),
        ]);

        Post::factory()->forAccount($own)->create([
            'posted_at' => now()->subDays(2),
            'metrics' => ['views' => 500, 'likes' => 40, 'comments' => 4, 'shares' => 0],
        ]);

        $this->actingAs($user)
            ->get(route('growth.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('growth/Index')
                ->where('period', '30d')
                ->where('metrics.thin_data', true)
                ->where('metrics.snapshot_count', 1)
                ->where('metrics.note', fn ($note): bool => is_string($note) && str_contains($note, 'Only one follower snapshot'))
                ->has('metrics.charts.followers')
            );
    }

    public function test_growth_chips_and_charts_share_handle_colour_map(): void
    {
        $index = file_get_contents(resource_path('js/pages/growth/Index.vue'));
        $chart = file_get_contents(resource_path('js/components/growth/GrowthLineChart.vue'));
        $theme = file_get_contents(resource_path('js/lib/snitchTheme.ts'));
        $lineChart = file_get_contents(resource_path('js/lib/lineChart.ts'));
        $appearance = file_get_contents(resource_path('js/composables/useAppearance.ts'));

        $this->assertIsString($index);
        $this->assertIsString($chart);
        $this->assertIsString($theme);
        $this->assertIsString($lineChart);
        $this->assertIsString($appearance);
        $this->assertStringContainsString('snitchAccountColour', $index);
        $this->assertStringContainsString('snitchAccountColour', $chart);
        $this->assertStringContainsString('connectedLineData', $chart);
        $this->assertStringContainsString("type: 'datetime'", $chart);
        $this->assertStringContainsString('youStrokeWidth', $chart);
        $this->assertStringContainsString('export function connectedLineData', $lineChart);
        $this->assertStringContainsString('export function snitchAccountColour', $theme);
        $this->assertStringContainsString('snitchNormalizeHandle', $theme);
        $this->assertStringNotContainsString('RIVAL_COLOURS', $index);
        $this->assertStringNotContainsString('rivalIndex', $chart);
        $this->assertStringContainsString("type: 'datetime' as const", $chart);
        $this->assertStringContainsString("style.colorScheme = dark ? 'dark' : 'light'", $appearance);
    }

    public function test_own_account_weekly_metrics_null_out_empty_weeks_but_keep_posted_weeks(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => true,
            'handle' => 'goodgym',
            'followers' => 1200,
        ]);

        $thisWeek = CarbonImmutable::now('Europe/London')->startOfWeek(CarbonImmutable::MONDAY);
        $weekA = $thisWeek->subWeeks(3);
        $weekB = $thisWeek->subWeeks(2);
        $weekC = $thisWeek->subWeeks(1);

        Post::factory()->forAccount($own)->create([
            'posted_at' => $weekA->addDays(1),
            'metrics' => ['views' => 800, 'likes' => 80, 'comments' => 8, 'shares' => 2],
        ]);
        Post::factory()->forAccount($own)->create([
            'posted_at' => $weekC->addDays(2),
            'metrics' => ['views' => 600, 'likes' => 40, 'comments' => 4, 'shares' => 1],
        ]);

        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 1200,
            'captured_on' => $weekA->toDateString(),
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 1250,
            'captured_on' => $weekC->toDateString(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('growth.index', ['period' => '30d']))
            ->assertOk();

        $charts = $response->original->getData()['page']['props']['metrics']['charts'] ?? null;
        $this->assertIsArray($charts);

        foreach (['posts_per_week', 'engagement_rate'] as $key) {
            $you = collect($charts[$key] ?? [])->firstWhere('name', 'You');
            $this->assertNotNull($you, $key);
            $byDate = collect($you['points'] ?? [])->keyBy('date');

            $pointA = $byDate->get($weekA->toDateString());
            $pointB = $byDate->get($weekB->toDateString());
            $pointC = $byDate->get($weekC->toDateString());

            $this->assertIsArray($pointA, $key.' week A');
            $this->assertIsArray($pointB, $key.' week B');
            $this->assertIsArray($pointC, $key.' week C');
            $this->assertNotNull($pointA['value'] ?? null, $key.' week A value');
            $this->assertNull($pointB['value'] ?? null, $key.' week B gap');
            $this->assertNotNull($pointC['value'] ?? null, $key.' week C value');
        }

        $multiplier = collect($charts['avg_multiplier'] ?? [])->firstWhere('name', 'You');
        $this->assertNotNull($multiplier);
        $this->assertNull(
            collect($multiplier['points'] ?? [])->firstWhere('date', $weekB->toDateString())['value'] ?? null,
        );

        $followers = collect($charts['followers'] ?? [])->firstWhere('name', 'You');
        $this->assertNotNull($followers);
        $followerValues = collect($followers['points'] ?? [])->pluck('value')->filter(fn ($v) => $v !== null);
        $this->assertGreaterThanOrEqual(2, $followerValues->count());
    }

    public function test_growth_charts_exclude_current_incomplete_week(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => true,
            'handle' => 'goodgym',
            'followers' => 1000,
        ]);

        $thisWeek = CarbonImmutable::now('Europe/London')->startOfWeek(CarbonImmutable::MONDAY);
        $lastWeek = $thisWeek->subWeek();

        Post::factory()->forAccount($own)->create([
            'posted_at' => $lastWeek->addDays(1),
            'metrics' => ['views' => 500, 'likes' => 40, 'comments' => 4, 'shares' => 0],
        ]);
        Post::factory()->forAccount($own)->create([
            'posted_at' => $thisWeek->addHours(2),
            'metrics' => ['views' => 500, 'likes' => 40, 'comments' => 4, 'shares' => 0],
        ]);

        $response = $this->actingAs($user)
            ->get(route('growth.index', ['period' => '30d']))
            ->assertOk();

        $series = $response->original->getData()['page']['props']['metrics']['charts']['posts_per_week'] ?? null;
        $this->assertIsArray($series);
        foreach ($series as $row) {
            foreach ($row['points'] ?? [] as $point) {
                $this->assertNotSame(
                    $thisWeek->toDateString(),
                    $point['date'] ?? null,
                    json_encode($series),
                );
            }
        }

        $you = collect($series)->firstWhere('name', 'You');
        $this->assertNotNull($you);
        $lastWeekPoint = collect($you['points'] ?? [])->firstWhere('date', $lastWeek->toDateString());
        $this->assertNotNull($lastWeekPoint);
        $this->assertSame(1.0, (float) $lastWeekPoint['value']);
    }
}
