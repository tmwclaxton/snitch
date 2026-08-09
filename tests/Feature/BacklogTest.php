<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BacklogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('backlog.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_without_brand_are_sent_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('backlog.index'))
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_backlog_lists_queue_posts_for_owner(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $completed = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($completed)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $pending = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($pending)->pending()->create();

        $unsynced = Post::factory()->forAccount($account)->create();

        $this->actingAs($user)
            ->get(route('backlog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('backlog/Index')
                ->where('filter', 'queue')
                ->where('counts.queue', 2)
                ->where('counts.failed', 0)
                ->has('posts.data', 2)
                ->has('posts.data.0.embed')
            );
    }

    public function test_backlog_failed_filter_lists_failed_posts_only(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $failed = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($failed)->create([
            'status' => AnalysisStatus::Failed,
            'error_message' => 'Analysis failed checklist: hook too short',
        ]);

        $pending = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($pending)->pending()->create();

        $this->actingAs($user)
            ->get(route('backlog.index', ['filter' => 'failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('backlog/Index')
                ->where('filter', 'failed')
                ->where('counts.queue', 1)
                ->where('counts.failed', 1)
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $failed->id)
            );
    }

    public function test_backlog_does_not_include_other_users_posts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        BrandProfile::factory()->for($other)->create();

        $account = TrackedAccount::factory()->for($user)->create();
        Post::factory()->forAccount($account)->create();

        $otherAccount = TrackedAccount::factory()->for($other)->create();
        Post::factory()->forAccount($otherAccount)->create();

        $this->actingAs($user)
            ->get(route('backlog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 1)
            );
    }
}
