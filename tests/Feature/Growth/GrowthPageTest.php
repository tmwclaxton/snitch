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
                ->where('metrics.note', fn ($note): bool => is_string($note) && str_contains($note, 'Only one weekly snapshot'))
                ->has('metrics.charts.followers')
            );
    }
}
