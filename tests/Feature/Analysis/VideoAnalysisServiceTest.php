<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\AnalysisTerm;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\VideoAnalysisService;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideoAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_url_parses_structured_result(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'concept' => 'Steam tease before product payoff',
                            'hook' => 'Steam hits the lens first',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Matte bakery counter with torn paper overlays and soft fade. ', 2),
                            'idea' => 'Curiosity gap then proof via finished loaf',
                            'topics' => ['process reveal'],
                            'hook_type_slugs' => ['silent_visual_hook'],
                            'topic_slugs' => ['content_strategy'],
                            'visual_craft_slugs' => ['broll_overlay'],
                            'custom_tags' => [],
                            'cta' => 'Preorder tomorrow',
                            'how_to_copy' => 'Start on steam, cut to hands, end on boxed loaf.',
                            'sfx' => [
                                ['at_sec' => 0.2, 'label' => 'whoosh', 'role' => 'transition'],
                            ],
                            'music_title' => null,
                            'music_artist' => null,
                            'is_original_audio' => true,
                        ]),
                    ],
                ]],
            ]),
        ]);

        $result = app(VideoAnalysisService::class)->analyzeUrl('https://cdn.example.com/reel.mp4');

        $this->assertSame('Steam hits the lens first', $result->hook);
        $this->assertSame('Steam tease before product payoff', $result->concept);
        $this->assertGreaterThanOrEqual(3.0, $result->hookWindowEndSeconds);
        $this->assertSame('qwen3.7-flash', $result->model);
        $this->assertNotEmpty($result->sfx);
        $this->assertSame(['silent_visual_hook'], $result->hookTypeSlugs);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $encoded = json_encode($payload);

            return is_string($encoded)
                && str_contains($encoded, 'Write every string value in English')
                && str_contains($encoded, 'Language = English only')
                && str_contains($encoded, 'hook_type_slugs')
                && str_contains($encoded, 'Controlled catalogue');
        });
    }

    public function test_analyze_post_persists_completed_analysis(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'concept' => 'Steam tease before product payoff',
                            'hook' => 'Steam hits the lens first',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Matte bakery counter with torn paper overlays and soft fade. ', 2),
                            'idea' => 'Curiosity gap then proof via finished loaf',
                            'topics' => ['process reveal', 'bakery ASMR'],
                            'hook_type_slugs' => ['silent_visual_hook', 'not_a_real_slug'],
                            'topic_slugs' => ['content_strategy'],
                            'visual_craft_slugs' => ['broll_overlay'],
                            'custom_tags' => ['steam-asmr'],
                            'cta' => 'Preorder tomorrow',
                            'how_to_copy' => 'Start on steam, cut to hands, end on boxed loaf.',
                            'sfx' => [],
                            'is_original_audio' => true,
                        ]),
                    ],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/reel.mp4',
        ]);

        $analysis = app(VideoAnalysisService::class)->analyzePost($post);

        $this->assertSame(AnalysisStatus::Completed, $analysis->status);
        $this->assertSame('Steam hits the lens first', $analysis->hook);
        $this->assertSame('Steam tease before product payoff', $analysis->concept);
        $this->assertSame('Start on steam, cut to hands, end on boxed loaf.', $analysis->how_to_copy);
        $this->assertContains('process reveal', $analysis->topics);
        $this->assertContains('bakery ASMR', $analysis->topics);
        $this->assertContains('Silent visual hook', $analysis->topics);
        $this->assertContains('steam-asmr', $analysis->topics);
        $this->assertSame(['steam-asmr'], $analysis->custom_tags);
        $this->assertSame(3, $analysis->hook_window_end_sec);
        $this->assertSame(3, $analysis->terms()->count());
        $this->assertFalse(
            $analysis->terms()->where('slug', 'not_a_real_slug')->exists()
        );
        $this->assertTrue(
            AnalysisTerm::query()->where('slug', 'silent_visual_hook')->exists()
        );
    }
}
