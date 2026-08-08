<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetitorShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_competitor_profile_with_posts_and_winners(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create([
            'handle' => 'rivalbakery',
            'display_name' => 'Rival Bakery',
            'last_synced_at' => now()->subDays(2),
            'last_sync_status' => 'success',
        ]);

        $post = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($post)->create([
            'concept' => 'Proof before pitch',
            'hook' => 'Opens on the receipt',
            'topics' => ['social proof', 'receipts'],
        ]);
        WinnerInsight::factory()->forPost($post)->create([
            'score' => 88.5,
            'why' => 'Clear proof hook',
        ]);

        $this->actingAs($user)
            ->get(route('competitors.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Show')
                ->where('account.id', $account->id)
                ->where('account.handle', 'rivalbakery')
                ->where('account.last_sync_status', 'success')
                ->has('posts', 1)
                ->where('posts.0.id', $post->id)
                ->where('posts.0.analysis.concept', 'Proof before pitch')
                ->has('winners', 1)
                ->where('winners.0.score', 88.5)
            );

        $showVue = file_get_contents(resource_path('js/pages/competitors/Show.vue'));
        $this->assertIsString($showVue);
        $this->assertStringContainsString('Open on platform', $showVue);
        $this->assertStringContainsString('border-b border-snitch-ink/10', $showVue);
        $this->assertStringNotContainsString('snitch-cutout--hero', $showVue);
        $this->assertStringContainsString('RemoveCompetitorModal', $showVue);
        $this->assertStringContainsString('askRemove', $showVue);
        $this->assertStringContainsString('isSyncing', $showVue);
        $this->assertStringContainsString('Sync in progress', $showVue);
        $this->assertStringNotContainsString('confirm(`Remove', $showVue);
        $this->assertStringNotContainsString('Sync ok', $showVue);
        $this->assertStringNotContainsString('Not synced yet', $showVue);
    }

    public function test_profile_exposes_running_sync_status(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'handle' => 'rivalbakery',
            'last_sync_status' => 'running',
        ]);

        $this->actingAs($user)
            ->get(route('competitors.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Show')
                ->where('account.last_sync_status', 'running')
            );
    }

    public function test_other_user_cannot_view_competitor_profile(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($other)->create();
        $account = TrackedAccount::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('competitors.show', $account))
            ->assertForbidden();
    }
}
