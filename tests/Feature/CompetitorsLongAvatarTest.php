<?php

namespace Tests\Feature;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class CompetitorsLongAvatarTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_confirm_suggestions_persists_tiktok_cdn_avatar_urls_over_255_chars(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $avatar = 'https://p16-common-sign.tiktokcdn-us.com/tos-alisg-avt-0068/9038beff4baf8e817bf8c7e34eb5611b~tplv-tiktokx-cropcenter:720:720.jpeg?dr=9640&refresh_token=433bf7f3&x-expires=1786381200&x-signature=tXe7qIHdTjO9zEvjpy0rMQqvggk%3D&t=4d5b0474&ps=13740610&shp=a5d48078&shcp=81f88b70&idc=useast5';

        $this->assertGreaterThan(255, strlen($avatar));

        $this->actingAs($user)
            ->post(route('competitors.confirm-suggestions'), [
                'suggestions' => [
                    [
                        'platform' => 'tiktok',
                        'handle' => 'theplatformza',
                        'display_name' => 'Lerato | Business Strategist',
                        'avatar' => $avatar,
                    ],
                ],
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'theplatformza',
            'platform' => 'tiktok',
            'avatar' => $avatar,
        ]);

        Queue::assertPushed(SyncTrackedAccountJob::class);
    }
}
