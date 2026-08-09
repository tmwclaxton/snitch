<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExploreEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function explore_custom_tag_returns_exact_match_and_related_by_embedding(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.embeddings.enabled' => true,
            'snitch.embeddings.min_similarity' => 0.2,
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [1.0, 0.0, 0.0]],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $exact = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subDay(),
        ]);
        PostAnalysis::factory()->for($exact)->create([
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['foundation_report_drop'],
            'embedding' => [0.1, 0.9, 0.0],
        ]);

        $related = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subDays(2),
        ]);
        PostAnalysis::factory()->for($related)->create([
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['unrelated-label'],
            'embedding' => [0.95, 0.05, 0.0],
        ]);

        $noise = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subDays(3),
        ]);
        PostAnalysis::factory()->for($noise)->create([
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['other'],
            'embedding' => [0.0, 1.0, 0.0],
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['custom_tag' => 'foundation_report_drop']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->where('filters.custom_tag', 'foundation_report_drop')
                ->has('posts.data', 2)
                ->where('posts.data.0.id', $exact->id)
                ->where('posts.data.1.id', $related->id)
            );
    }

    #[Test]
    public function explore_custom_tag_falls_back_to_exact_json_when_embeddings_disabled(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.embeddings.enabled' => false,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['foundation_report_drop'],
        ]);

        PostAnalysis::factory()->for(
            Post::factory()->forAccount($account)->create(['type' => PostType::Reel])
        )->create([
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['other'],
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['custom_tag' => 'foundation_report_drop']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $post->id)
                ->where('filters.custom_tag', 'foundation_report_drop')
            );
    }

    #[Test]
    public function explore_q_ranks_by_embedding_similarity(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.embeddings.enabled' => true,
            'snitch.embeddings.min_similarity' => 0.2,
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [1.0, 0.0]],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $match = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($match)->create([
            'status' => AnalysisStatus::Completed,
            'concept' => 'Steam kitchen ASMR',
            'embedding' => [0.99, 0.01],
        ]);

        $other = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($other)->create([
            'status' => AnalysisStatus::Completed,
            'concept' => 'Office spreadsheet tips',
            'embedding' => [0.0, 1.0],
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['q' => 'steam asmr cooking']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $match->id)
            );
    }

    #[Test]
    public function explore_index_surfaces_custom_tag_chip_wiring(): void
    {
        $indexVue = file_get_contents(resource_path('js/pages/explore/Index.vue'));
        $postsLib = file_get_contents(resource_path('js/lib/posts.ts'));
        $termsLib = file_get_contents(resource_path('js/lib/analysisTerms.ts'));

        $this->assertIsString($indexVue);
        $this->assertIsString($postsLib);
        $this->assertIsString($termsLib);
        $this->assertStringContainsString('custom_tag', $indexVue);
        $this->assertStringContainsString('humanizeTagLabel', $indexVue);
        $this->assertStringContainsString('searchValue', $postsLib);
        $this->assertStringContainsString('custom_tag: customTag', $termsLib);
    }
}
