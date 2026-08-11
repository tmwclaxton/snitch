<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
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
                'usage' => [
                    'prompt_tokens' => 1200,
                    'completion_tokens' => 400,
                ],
            ]),
        ]);

        $result = app(VideoAnalysisService::class)->analyzeUrl('https://cdn.example.com/reel.mp4');

        $this->assertSame('Steam hits the lens first', $result->hook);
        $this->assertSame('Steam tease before product payoff', $result->concept);
        $this->assertGreaterThanOrEqual(3.0, $result->hookWindowEndSeconds);
        $this->assertSame('qwen3.7-flash', $result->model);
        $this->assertNotEmpty($result->sfx);
        $this->assertSame(['silent_visual_hook'], $result->hookTypeSlugs);
        $this->assertSame(1200, $result->promptTokens);
        $this->assertSame(400, $result->completionTokens);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $encoded = json_encode($payload);

            return is_string($encoded)
                && str_contains($encoded, 'Write every string value in English (UK)')
                && str_contains($encoded, 'Language = English (UK)')
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

        $this->assertSame(AnalysisStatus::Completed, $analysis['analysis']->status);
        $this->assertSame('Steam hits the lens first', $analysis['analysis']->hook);
        $this->assertSame('Steam tease before product payoff', $analysis['analysis']->concept);
        $this->assertSame('Start on steam, cut to hands, end on boxed loaf.', $analysis['analysis']->how_to_copy);
        $this->assertContains('process reveal', $analysis['analysis']->topics);
        $this->assertContains('bakery ASMR', $analysis['analysis']->topics);
        $this->assertContains('Silent visual hook', $analysis['analysis']->topics);
        $this->assertContains('steam-asmr', $analysis['analysis']->topics);
        $this->assertSame(['steam-asmr'], $analysis['analysis']->custom_tags);
        $this->assertSame(3, $analysis['analysis']->hook_window_end_sec);
        $this->assertGreaterThanOrEqual(3, $analysis['analysis']->terms()->count());
        $this->assertTrue($analysis['analysis']->terms()->where('slug', 'silent_visual_hook')->exists());
        $this->assertTrue($analysis['analysis']->terms()->where('slug', 'content_strategy')->exists());
        $this->assertTrue($analysis['analysis']->terms()->where('slug', 'broll_overlay')->exists());
        $this->assertFalse(
            $analysis['analysis']->terms()->where('slug', 'not_a_real_slug')->exists()
        );
    }

    public function test_analyze_post_prefers_platform_music_metadata(): void
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
                            'concept' => 'Trend audio under a process montage',
                            'hook' => 'Beat drop on first cut',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Quick cuts of hands shaping dough under neon accent lighting. ', 2),
                            'idea' => 'Familiar trend audio lifts retention',
                            'topics' => ['process montage'],
                            'hook_type_slugs' => ['trend_audio_open'],
                            'topic_slugs' => ['content_strategy'],
                            'visual_craft_slugs' => ['quick_cuts_montage'],
                            'custom_tags' => [],
                            'cta' => 'No explicit CTA',
                            'how_to_copy' => "1. Open on the beat\n2. Cut on downbeats\n3. End on product",
                            'sfx' => [],
                            'music_title' => 'Wrong Guess',
                            'music_artist' => 'Hallucinated',
                            'is_original_audio' => false,
                        ]),
                    ],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/music.mp4',
            'raw_payload' => [
                'normalized_music' => [
                    'musicName' => 'Sometimes',
                    'musicAuthor' => 'Fleetwood Mac',
                    'musicOriginal' => false,
                    'musicId' => 'fleet-1',
                ],
            ],
        ]);

        $outcome = app(VideoAnalysisService::class)->analyzePost($post);

        $this->assertSame(AnalysisStatus::Completed, $outcome['analysis']->status);
        $this->assertSame('Sometimes', $outcome['analysis']->music['title']);
        $this->assertSame('Fleetwood Mac', $outcome['analysis']->music['artist']);
        $this->assertSame('platform', $outcome['analysis']->music['source']);
        $this->assertSame('fleet-1', $outcome['analysis']->music['platform_id']);
        $this->assertFalse($outcome['analysis']->music['is_original_audio']);

        Http::assertSent(function ($request): bool {
            $encoded = json_encode($request->data());

            return is_string($encoded)
                && str_contains($encoded, 'Music metadata (authoritative via platform metadata)')
                && str_contains($encoded, 'Sometimes')
                && str_contains($encoded, 'particle_fx');
        });
    }

    public function test_analyze_post_infers_catalogue_terms_when_model_omits_slugs(): void
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
                            'concept' => "A split-screen 'myth-busting' graphic with a gated Q&A payoff.",
                            'hook' => 'Text overlay LIE stamp FALSE on a grant myth.',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Split screen with bold FALSE stamp and talking-head replies. ', 2),
                            'idea' => 'Myth callout then exclusivity gate for the lead magnet.',
                            'topics' => ['myth-busting hook', 'lead magnet gating'],
                            'hook_type_slugs' => [],
                            'topic_slugs' => [],
                            'visual_craft_slugs' => [],
                            'custom_tags' => [],
                            'cta' => 'Comment MYTH for the guide',
                            'how_to_copy' => 'Open on the false claim stamp, cut to the gate, end on the CTA.',
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
            'media_url' => 'https://cdn.example.com/myth.mp4',
        ]);

        $outcome = app(VideoAnalysisService::class)->analyzePost($post);

        $this->assertSame(AnalysisStatus::Completed, $outcome['analysis']->status);
        $this->assertTrue(
            $outcome['analysis']->terms()->where('slug', 'myth_bust')->exists(),
        );
        $this->assertContains('Myth bust', $outcome['analysis']->topics);
    }
}
