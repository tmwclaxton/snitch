<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExploreMixTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function explore_surfaces_strong_posts_before_weak_score_outliers(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.5,
            'snitch.explore.jitter' => 0.2,
            'snitch.explore.weight_exponent' => 2.0,
            'snitch.explore.seed_bucket_hours' => 6,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $strongA = $this->completedPost($account, now()->subDays(1));
        $strongB = $this->completedPost($account, now()->subDays(2));
        $weak = $this->completedPost($account, now()->subHour());

        WinnerInsight::factory()->forPost($strongA)->create(['score' => 92]);
        WinnerInsight::factory()->forPost($strongB)->create(['score' => 88]);
        WinnerInsight::factory()->forPost($weak)->create(['score' => 8]);

        $this->actingAs($user)
            ->get(route('explore.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 3)
                ->where('posts.data.0.id', fn ($id) => in_array((int) $id, [$strongA->id, $strongB->id], true))
                ->where('posts.data.1.id', fn ($id) => in_array((int) $id, [$strongA->id, $strongB->id], true))
                ->where('posts.data.2.id', $weak->id)
            );
    }

    #[Test]
    public function explore_mix_order_is_not_pure_newest_first(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.2,
            'snitch.explore.jitter' => 0.1,
            'snitch.explore.weight_exponent' => 3.0,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $newestWeak = $this->completedPost($account, now()->subMinute());
        $olderStrong = $this->completedPost($account, now()->subDays(5));

        WinnerInsight::factory()->forPost($newestWeak)->create(['score' => 12]);
        WinnerInsight::factory()->forPost($olderStrong)->create(['score' => 95]);

        $this->actingAs($user)
            ->get(route('explore.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 2)
                ->where('posts.data.0.id', $olderStrong->id)
                ->where('posts.data.1.id', $newestWeak->id)
            );
    }

    private function completedPost(TrackedAccount $account, mixed $postedAt): Post
    {
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => $postedAt,
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        return $post;
    }
}
