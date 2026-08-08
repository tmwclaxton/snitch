<?php

namespace Tests\Feature\Marketing;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\SnitchDailyPlatformStat;
use App\Models\SnitchDailyStat;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillAnalyticsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_rebuilds_daily_counters_from_domain_rows(): void
    {
        $user = User::factory()->create();
        $tiktok = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::TikTok,
        ]);
        $instagram = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
        ]);

        $tiktokPost = Post::factory()->forAccount($tiktok)->create([
            'type' => PostType::Reel,
            'created_at' => now()->subDays(2),
        ]);
        Post::factory()->forAccount($instagram)->create([
            'type' => PostType::Reel,
            'created_at' => now()->subDay(),
        ]);
        Post::factory()->forAccount($instagram)->create([
            'type' => PostType::Reel,
            'created_at' => now()->subDay(),
        ]);

        PostAnalysis::factory()->for($tiktokPost)->create([
            'status' => AnalysisStatus::Completed,
            'analyzed_at' => now()->subDays(2),
        ]);

        WinnerInsight::factory()->forPost($tiktokPost)->create([
            'created_at' => now()->subDays(2),
        ]);

        SnitchDailyStat::factory()->create([
            'date' => now()->toDateString(),
            'posts_count' => 999,
            'analyses_count' => 999,
            'winners_count' => 999,
        ]);

        $this->artisan('snitch:backfill-analytics')
            ->assertSuccessful()
            ->expectsOutputToContain('Backfilled analytics: 3 posts, 1 analyses, 1 winners.');

        $this->assertSame(3, (int) SnitchDailyStat::query()->sum('posts_count'));
        $this->assertSame(1, (int) SnitchDailyStat::query()->sum('analyses_count'));
        $this->assertSame(1, (int) SnitchDailyStat::query()->sum('winners_count'));
        $this->assertSame(0, SnitchDailyStat::query()->where('posts_count', 999)->count());

        $this->assertSame(1, (int) SnitchDailyPlatformStat::query()
            ->where('platform', Platform::TikTok)
            ->sum('posts_count'));
        $this->assertSame(2, (int) SnitchDailyPlatformStat::query()
            ->where('platform', Platform::Instagram)
            ->sum('posts_count'));
    }
}
