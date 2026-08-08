<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
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

class ExploreTest extends TestCase
{
    use RefreshDatabase;

    public function test_explore_uses_sectioned_multi_select_pickers(): void
    {
        $indexVue = file_get_contents(resource_path('js/pages/explore/Index.vue'));
        $pickerVue = file_get_contents(resource_path('js/components/PaperTermPicker.vue'));

        $this->assertIsString($indexVue);
        $this->assertIsString($pickerVue);
        $this->assertStringContainsString('PaperTermPicker', $indexVue);
        $this->assertStringContainsString('Open a catalogue picker', $indexVue);
        $this->assertStringContainsString('Browse every hook pattern by section', $indexVue);
        $this->assertStringContainsString('aria-pressed', $pickerVue);
        $this->assertStringContainsString('Clear selection', $pickerVue);
        $this->assertStringContainsString('Apply', $pickerVue);
    }

    public function test_explore_lists_completed_analyses_for_owner(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $term = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'pattern_interrupt')
            ->firstOrFail();
        $analysis->terms()->attach($term->id);

        $otherAccount = TrackedAccount::factory()->for($other)->create();
        $otherPost = Post::factory()->forAccount($otherAccount)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($otherPost)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->actingAs($user)
            ->get(route('explore.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $post->id)
                ->has('terms.hook_type')
                ->where('terms.hook_type.0.section', fn ($section) => is_string($section) && $section !== '')
                ->has('terms.topic')
                ->has('terms.visual_craft')
                ->where('filters.hook_types', [])
                ->missing('accounts')
                ->missing('filters.account')
            );
    }

    public function test_explore_filters_by_multiple_hook_type_slugs(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $matching = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $matchingAnalysis = PostAnalysis::factory()->for($matching)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Pattern break open',
        ]);
        $hookTerm = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'pattern_interrupt')
            ->firstOrFail();
        $matchingAnalysis->terms()->attach($hookTerm->id);

        $alsoMatching = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $alsoAnalysis = PostAnalysis::factory()->for($alsoMatching)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $boldTerm = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'bold_claim')
            ->firstOrFail();
        $alsoAnalysis->terms()->attach($boldTerm->id);

        $other = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $otherAnalysis = PostAnalysis::factory()->for($other)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $otherTerm = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'question_hook')
            ->firstOrFail();
        $otherAnalysis->terms()->attach($otherTerm->id);

        $this->actingAs($user)
            ->get(route('explore.index', [
                'hook_types' => ['pattern_interrupt', 'bold_claim'],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 2)
                ->where('filters.hook_types', ['pattern_interrupt', 'bold_claim'])
            );
    }

    public function test_explore_search_matches_custom_tags(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->create([
            'post_id' => $post->id,
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['foundation-report-drop'],
            'hook' => 'Cold open on PDF',
        ]);

        $other = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->create([
            'post_id' => $other->id,
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['unrelated'],
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['q' => 'foundation-report']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $post->id)
            );
    }
}
