<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\AnalysisTerm;
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

    public function test_replace_strips_mirrored_labels_and_drops_false_positive_terms(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Did you know that the business you are building is also building you',
            'concept' => 'Reframe business challenges as character-building exercises for founders.',
            'idea' => "Uses a 'myth_bust' mechanism by challenging the assumption that struggles are purely negative.",
            'visual_summary' => 'Static talking head shot of a woman against a plain wall.',
            'topics' => [
                'entrepreneurship',
                'leadership',
                'personal_brand',
                'Myth bust',
                'Talking head',
                'Personal brand',
            ],
        ]);

        $myth = AnalysisTerm::query()
            ->where('slug', 'myth_bust')
            ->firstOrFail();
        $brand = AnalysisTerm::query()
            ->where('slug', 'personal_brand')
            ->firstOrFail();
        $analysis->terms()->sync([$myth->id, $brand->id]);

        $this->artisan('snitch:backfill-analysis-terms', ['--replace' => true])
            ->assertSuccessful();

        $analysis->refresh();

        $this->assertFalse($analysis->terms()->where('slug', 'myth_bust')->exists());
        $this->assertTrue($analysis->terms()->where('slug', 'personal_brand')->exists());
        $this->assertTrue($analysis->terms()->where('slug', 'talking_head')->exists());
        $this->assertContains('entrepreneurship', $analysis->topics);
        $this->assertNotContains('Myth bust', $analysis->topics);
    }
}
