<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.tracked_accounts', 0)
                ->where('stats.posts', 0)
                ->where('stats.winners', 0)
                ->where('stats.analysis_backlog', 0)
                ->has('recent_posts', 0)
                ->has('top_winners', 0)
            );
    }

    public function test_dashboard_surfaces_live_counts_recent_posts_and_winners(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'last_synced_at' => now()->subHour(),
            'last_sync_status' => 'success',
        ]);

        $ready = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($ready)->create([
            'status' => AnalysisStatus::Completed,
            'concept' => 'Receipt cold open',
            'hook' => 'Starts on the total',
        ]);
        WinnerInsight::factory()->forPost($ready)->create([
            'score' => 77.0,
            'why' => 'Proof lands early',
        ]);

        $pending = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($pending)->pending()->create();

        Post::factory()->forAccount($account)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.tracked_accounts', 1)
                ->where('stats.posts', 3)
                ->where('stats.winners', 1)
                ->where('stats.analysis_backlog', 2)
                ->has('recent_posts', 3)
                ->has('top_winners', 1)
                ->where('top_winners.0.post.id', $ready->id)
                ->where('top_winners.0.post.analysis.concept', 'Receipt cold open')
            );
    }

    public function test_authenticated_users_without_brand_are_sent_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.show'));
    }
}
