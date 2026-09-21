<?php

namespace Tests\Feature;

use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Database\Seeders\LetsGoSocialDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LetsGoSocialDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_the_owner_brand_and_seeds_mixed_post_types(): void
    {
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

        $types = Post::query()
            ->whereIn('social_account_id', $user->trackedAccounts()->select('social_account_id'))
            ->pluck('type')
            ->map(fn ($type) => $type instanceof PostType ? $type->value : (string) $type)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertContains(PostType::Reel->value, $types);
        $this->assertContains(PostType::Image->value, $types);
        $this->assertContains(PostType::Carousel->value, $types);
    }
}
