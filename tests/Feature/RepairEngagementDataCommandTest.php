<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairEngagementDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_view_fix_without_writing(): void
    {
        $account = TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'type' => PostType::Reel,
            'metrics' => [
                'views' => 4,
                'likes' => 39,
                'comments' => 4,
                'shares' => 0,
            ],
            'raw_payload' => [
                'videoViewCount' => 4,
                'videoPlayCount' => 583,
            ],
        ]);

        $this->artisan('snitch:repair-engagement-data', [
            '--fix-views' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(4, (int) ($post->fresh()?->metrics['views'] ?? 0));
    }

    public function test_fix_views_and_merge_duplicates_and_backfill_followers(): void
    {
        $social = SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'yellowzest',
        ]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        TrackedAccount::factory()->for($owner)->forSocialAccount($social)->create([
            'followers' => 1158,
        ]);
        $second = TrackedAccount::factory()->for($other)->forSocialAccount($social)->create([
            'followers' => null,
        ]);
        // Creating hook may already seed; force null to exercise the repair path.
        TrackedAccount::query()->whereKey($second->id)->update(['followers' => null]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $social->id,
            'followers' => 1158,
            'captured_on' => now()->toDateString(),
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $social->id,
            'followers' => 1158,
            'captured_on' => now()->subDays(7)->toDateString(),
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $social->id,
            'followers' => 1158,
            'captured_on' => now()->subDays(30)->toDateString(),
        ]);

        $apify = Post::factory()->forAccount(
            TrackedAccount::query()->where('user_id', $owner->id)->firstOrFail(),
        )->create([
            'social_account_id' => $social->id,
            'platform' => Platform::Instagram,
            'external_id' => '999888777',
            'url' => 'https://www.instagram.com/reel/DUPCODE/',
            'type' => PostType::Reel,
            'metrics' => [
                'views' => 4,
                'likes' => 39,
                'comments' => 4,
                'shares' => 0,
            ],
            'raw_payload' => [
                'videoViewCount' => 4,
                'videoPlayCount' => 583,
            ],
        ]);
        $tikhub = Post::factory()->create([
            'social_account_id' => $social->id,
            'platform' => Platform::Instagram,
            'external_id' => 'DUPCODE',
            'url' => 'https://www.instagram.com/reel/DUPCODE/',
            'type' => PostType::Reel,
            'metrics' => [
                'views' => 600,
                'likes' => 40,
                'comments' => 4,
                'shares' => 0,
            ],
            'raw_payload' => [],
        ]);

        $this->artisan('snitch:repair-engagement-data', [
            '--all' => true,
        ])->assertSuccessful();

        $this->assertNull(Post::query()->find($apify->id));
        $keeper = Post::query()->find($tikhub->id);
        $this->assertNotNull($keeper);
        $this->assertSame('DUPCODE', $keeper->external_id);
        $this->assertSame(600, (int) ($keeper->metrics['views'] ?? 0));
        $this->assertSame(1158, $second->fresh()?->followers);
        $this->assertSame(1, FollowerSnapshot::query()->where('social_account_id', $social->id)->count());
    }
}
