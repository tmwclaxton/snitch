<?php

namespace Tests\Feature;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Database\Seeders\LetsGoSocialDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LetsGoSocialDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_the_owner_brand_and_queues_real_instagram_syncs(): void
    {
        Queue::fake([SyncTrackedAccountJob::class]);

        $user = User::factory()->create([
            'email' => LetsGoSocialDemoSeeder::OWNER_EMAIL,
        ]);
        BrandProfile::factory()->for($user)->create([
            'name' => 'Mercury',
            'own_handles' => ['instagram' => '@mercuryfi'],
        ]);
        TrackedAccount::factory()->for($user)->create([
            'handle' => 'brex',
        ]);

        $this->seed(LetsGoSocialDemoSeeder::class);

        $user->refresh();
        $this->assertSame("Let's Go Social", $user->brandProfile?->name);
        $this->assertSame('@letsgosocialuk', $user->brandProfile?->own_handles['instagram'] ?? null);
        $this->assertSame(3, $user->trackedAccounts()->count());
        $this->assertFalse($user->trackedAccounts()->where('handle', 'brex')->exists());
        $this->assertSame(0, Post::query()->where('external_id', 'like', 'demo-%')->count());

        Queue::assertPushed(SyncTrackedAccountJob::class, 3);
        Queue::assertPushed(
            SyncTrackedAccountJob::class,
            fn (SyncTrackedAccountJob $job): bool => $job->force === true,
        );
    }
}
