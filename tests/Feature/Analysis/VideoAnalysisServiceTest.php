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

    public function test_analyze_post_persists_transcript_when_model_returns_one(): void
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
                            'concept' => 'Grant myth-bust hook then lead magnet gate',
                            'hook' => 'Text overlay STOP DOING THIS on false grant advice',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Split screen bold FALSE stamp with talking-head replies. ', 2),
                            'idea' => 'Myth callout then exclusivity gate for the lead magnet.',
                            'topics' => ['grants'],
                            'hook_type_slugs' => ['pattern_interrupt'],
                            'topic_slugs' => ['content_strategy'],
                            'visual_craft_slugs' => ['talking_head'],
                            'custom_tags' => [],
                            'cta' => 'Comment MYTH for the guide',
                            'how_to_copy' => "1. Cold open on the false claim\n2. Cut to your correction\n3. Gate the deeper answer",
                            'transcript' => "Stop applying for grants like this.\nHere is what actually works.\nComment MYTH and I will send you the checklist.",
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

        $outcome = app(VideoAnalysisService::class)->analyzePost($post);

        $this->assertSame(AnalysisStatus::Completed, $outcome['analysis']->status);
        $this->assertSame(
            "Stop applying for grants like this.\nHere is what actually works.\nComment MYTH and I will send you the checklist.",
            $outcome['analysis']->transcript,
        );
    }

    public function test_analyze_post_stores_null_transcript_when_reel_is_silent(): void
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
                            'concept' => 'Silent product tease with printed text pack',
                            'hook' => 'Bold FALSE stamp lands before the reveal',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Riso paper backdrop with hand-drawn arrows and typography reveal. ', 2),
                            'idea' => 'Curiosity from a silent typographic tease',
                            'topics' => ['silent hook'],
                            'hook_type_slugs' => ['silent_visual_hook'],
                            'topic_slugs' => ['content_strategy'],
                            'visual_craft_slugs' => ['sticker_pack_overlay'],
                            'custom_tags' => [],
                            'cta' => 'No explicit CTA',
                            'how_to_copy' => "1. Print a bold stamp\n2. Reveal on the beat\n3. End on your product",
                            'transcript' => '',
                            'sfx' => [],
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
            'media_url' => 'https://cdn.example.com/silent.mp4',
        ]);

        $outcome = app(VideoAnalysisService::class)->analyzePost($post);

        $this->assertSame(AnalysisStatus::Completed, $outcome['analysis']->status);
        $this->assertNull($outcome['analysis']->transcript);
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

    public function test_analyze_url_sends_configured_max_tokens(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.video_analysis.max_tokens' => 5120,
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
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
                            'transcript' => '',
                            'sfx' => [],
                            'is_original_audio' => true,
                        ]),
                    ],
                ]],
            ]),
        ]);

        app(VideoAnalysisService::class)->analyzeUrl('https://cdn.example.com/reel.mp4');

        Http::assertSent(function ($request): bool {
            return ($request->data()['max_tokens'] ?? null) === 5120;
        });
    }

    public function test_analyze_post_persists_long_transcript_without_truncating(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $longTranscript = implode("\n", array_fill(0, 40, 'This is sentence number one in a long talking-head reel that must survive end to end.'));

        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => json_encode([
                            'concept' => 'Grant myth-bust hook then lead magnet gate',
                            'hook' => 'Text overlay STOP DOING THIS on false grant advice',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Split screen bold FALSE stamp with talking-head replies. ', 2),
                            'idea' => 'Myth callout then exclusivity gate for the lead magnet.',
                            'topics' => ['grants'],
                            'hook_type_slugs' => ['pattern_interrupt'],
                            'topic_slugs' => ['content_strategy'],
                            'visual_craft_slugs' => ['talking_head'],
                            'custom_tags' => [],
                            'cta' => 'Comment MYTH for the guide',
                            'how_to_copy' => "1. Cold open on the false claim\n2. Cut to your correction\n3. Gate the deeper answer",
                            'transcript' => $longTranscript,
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

        $outcome = app(VideoAnalysisService::class)->analyzePost($post);

        $this->assertSame(AnalysisStatus::Completed, $outcome['analysis']->status);
        $this->assertSame($longTranscript, $outcome['analysis']->transcript);
        $this->assertGreaterThan(2000, strlen((string) $outcome['analysis']->transcript));
    }

    public function test_analyze_post_appends_truncation_notice_when_finish_reason_is_length(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'length',
                    'message' => [
                        'content' => json_encode([
                            'concept' => 'Grant myth-bust hook then lead magnet gate',
                            'hook' => 'Text overlay STOP DOING THIS on false grant advice',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Split screen bold FALSE stamp with talking-head replies. ', 2),
                            'idea' => 'Myth callout then exclusivity gate for the lead magnet.',
                            'topics' => ['grants'],
                            'hook_type_slugs' => ['pattern_interrupt'],
                            'topic_slugs' => ['content_strategy'],
                            'visual_craft_slugs' => ['talking_head'],
                            'custom_tags' => [],
                            'cta' => 'Comment MYTH for the guide',
                            'how_to_copy' => "1. Cold open on the false claim\n2. Cut to your correction\n3. Gate the deeper answer",
                            'transcript' => 'Stop applying for grants like this.',
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

        $outcome = app(VideoAnalysisService::class)->analyzePost($post);

        $this->assertSame(AnalysisStatus::Completed, $outcome['analysis']->status);
        $this->assertStringContainsString(
            'Stop applying for grants like this.',
            (string) $outcome['analysis']->transcript,
        );
        $this->assertStringContainsString(
            '[Output limit reached; transcript may be incomplete. Re-analyze to retry.]',
            (string) $outcome['analysis']->transcript,
        );
    }

    public function test_video_analysis_default_max_tokens_is_16384(): void
    {
        $this->assertSame(16384, (int) config('snitch.video_analysis.max_tokens'));
    }

    public function test_analyze_url_sends_default_max_tokens_when_not_overridden(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.video_analysis.max_tokens' => 16384,
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
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
                            'transcript' => '',
                            'sfx' => [],
                            'is_original_audio' => true,
                        ]),
                    ],
                ]],
            ]),
        ]);

        app(VideoAnalysisService::class)->analyzeUrl('https://cdn.example.com/reel.mp4');

        Http::assertSent(function ($request): bool {
            return ($request->data()['max_tokens'] ?? null) === 16384;
        });
    }

    public function test_analyze_url_prompt_prioritizes_full_transcript(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => json_encode([
                            'concept' => 'Grant myth-bust hook then lead magnet gate',
                            'hook' => 'Text overlay STOP DOING THIS on false grant advice',
                            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                            'visual_summary' => str_repeat('Split screen bold FALSE stamp with talking-head replies. ', 2),
                            'idea' => 'Myth callout then exclusivity gate for the lead magnet.',
                            'topics' => ['grants'],
                            'hook_type_slugs' => ['pattern_interrupt'],
                            'topic_slugs' => ['content_strategy'],
                            'visual_craft_slugs' => ['talking_head'],
                            'custom_tags' => [],
                            'cta' => 'Comment MYTH for the guide',
                            'how_to_copy' => "1. Cold open on the false claim\n2. Cut to your correction\n3. Gate the deeper answer",
                            'transcript' => 'Stop applying for grants like this.',
                            'sfx' => [],
                            'is_original_audio' => true,
                        ]),
                    ],
                ]],
            ]),
        ]);

        app(VideoAnalysisService::class)->analyzeUrl('https://cdn.example.com/reel.mp4');

        Http::assertSent(function ($request): bool {
            $messages = $request->data()['messages'] ?? [];
            $system = is_array($messages[0]['content'] ?? null) ? '' : (string) ($messages[0]['content'] ?? '');
            $userParts = $messages[1]['content'] ?? [];
            $userText = '';

            if (is_array($userParts)) {
                foreach ($userParts as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'text') {
                        $userText .= (string) ($part['text'] ?? '');
                    }
                }
            }

            return str_contains($system, 'reserve most of your output token budget for transcript')
                && str_contains($userText, 'highest priority when speech is present')
                && str_contains($userText, 'Never stop mid-sentence or mid-reel');
        });
    }
}
