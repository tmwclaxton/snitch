<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BackfillAnalysisTermsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_attaches_myth_bust_so_explore_filter_matches(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => "Visual text overlay: 'LIE: Just get your 501(c)(3)' stamped FALSE.",
            'concept' => "Split-screen 'myth-busting' graphic with a live Q&A gate.",
            'idea' => 'Myth callout then gated exclusive content.',
            'topics' => ['myth-busting hook', 'lead magnet gating'],
        ]);

        $this->artisan('snitch:backfill-analysis-terms')
            ->assertSuccessful();

        $this->assertTrue(
            $post->fresh()->analysis->terms()->where('slug', 'myth_bust')->exists(),
        );

        $this->actingAs($user)
            ->get(route('explore.index', ['hook_types' => ['myth_bust']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $post->id)
            );
    }
}
