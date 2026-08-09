<?php

namespace Tests\Unit\Services\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\AnalysisEmbeddingService;
use App\Services\Analysis\NanoGptClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalysisEmbeddingServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function build_source_text_joins_analysis_fields(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.embeddings.enabled' => true,
        ]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'caption' => 'Caption line',
        ]);
        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'concept' => 'Grant myth bust',
            'hook' => 'Cold open PDF',
            'idea' => 'Trust through docs',
            'visual_summary' => 'Hands flip pages',
            'topics' => ['fundraising'],
            'custom_tags' => ['foundation_report_drop'],
            'how_to_copy' => "1. Open with PDF\n2. State the myth",
        ]);

        $text = app(AnalysisEmbeddingService::class)->buildSourceText($analysis->load('post'));

        $this->assertStringContainsString('Grant myth bust', $text);
        $this->assertStringContainsString('foundation_report_drop', $text);
        $this->assertStringContainsString('Caption line', $text);
    }

    #[Test]
    public function embed_analysis_persists_vector_and_skips_unchanged_hash(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.embeddings.enabled' => true,
            'snitch.embeddings.model' => 'text-embedding-3-small',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'concept' => 'Steam ASMR drop',
        ]);

        $service = app(AnalysisEmbeddingService::class);

        $this->assertTrue($service->embedAnalysis($analysis->load('post')));
        $analysis->refresh();
        $this->assertSame([0.1, 0.2, 0.3], $analysis->embedding);
        $this->assertSame('text-embedding-3-small', $analysis->embedding_model);
        $this->assertNotEmpty($analysis->embedding_hash);

        Http::fake([
            'https://nano-gpt.test/api/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [9.0, 9.0, 9.0]],
                ],
            ]),
        ]);

        $this->assertFalse($service->embedAnalysis($analysis->fresh()->load('post')));
        $this->assertSame([0.1, 0.2, 0.3], $analysis->fresh()->embedding);
        Http::assertNothingSent();
    }

    #[Test]
    public function rank_post_ids_boosts_exact_matches_and_orders_by_similarity(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.embeddings.enabled' => true,
            'snitch.embeddings.min_similarity' => 0.1,
        ]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $near = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($near)->create([
            'status' => AnalysisStatus::Completed,
            'embedding' => [0.9, 0.1, 0.0],
        ]);

        $far = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($far)->create([
            'status' => AnalysisStatus::Completed,
            'embedding' => [0.2, 0.8, 0.0],
        ]);

        $boosted = Post::factory()->forAccount($account)->create(['type' => PostType::Reel]);
        PostAnalysis::factory()->for($boosted)->create([
            'status' => AnalysisStatus::Completed,
            'embedding' => [0.0, 1.0, 0.0],
        ]);

        $posts = Post::query()
            ->whereIn('id', [$near->id, $far->id, $boosted->id])
            ->with('analysis')
            ->get();

        $ranked = app(AnalysisEmbeddingService::class)->rankPostIds(
            $posts,
            [1.0, 0.0, 0.0],
            [$boosted->id],
        );

        $this->assertSame($boosted->id, $ranked[0]);
        $this->assertSame($near->id, $ranked[1]);
        $this->assertContains($far->id, $ranked);
    }

    #[Test]
    public function nanogpt_client_embeddings_parses_vectors(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.5, 0.25]],
                ],
            ]),
        ]);

        $vectors = app(NanoGptClient::class)->embeddings('hello world');

        $this->assertSame([[0.5, 0.25]], $vectors);
    }
}
