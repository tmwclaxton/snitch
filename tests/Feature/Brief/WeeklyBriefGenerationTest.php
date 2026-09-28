<?php

namespace Tests\Feature\Brief;

use App\Enums\AnalysisStatus;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Services\Brief\WeeklyBriefGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WeeklyBriefGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_weekly_brief_is_free_and_uses_faked_llm(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.brief.model' => 'test-model',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'ideas' => [
                                [
                                    'format' => 'Reel',
                                    'hook' => 'Open on the proof',
                                    'caption_angle' => 'Show the swap in 15 seconds',
                                    'cta' => 'Save this',
                                    'hashtags' => ['#fitness', '#community', '#london'],
                                    'recommended_day' => 'Tue',
                                    'recommended_hour' => 11,
                                    'inspired_by_post_ids' => [],
                                    'why' => '@rival hit 3.2x usual on this format.',
                                ],
                                [
                                    'format' => 'Carousel',
                                    'hook' => 'Three mistakes',
                                    'caption_angle' => 'List the traps then the fix',
                                    'cta' => 'Comment 1 2 or 3',
                                    'hashtags' => ['#tips', '#growth', '#brand'],
                                    'recommended_day' => 'Thu',
                                    'recommended_hour' => 18,
                                    'inspired_by_post_ids' => [],
                                    'why' => 'Carousels from rivals cleared 2x usual.',
                                ],
                                [
                                    'format' => 'Image',
                                    'hook' => 'One bold claim',
                                    'caption_angle' => 'Single visual with a tight caption',
                                    'cta' => 'Share with a teammate',
                                    'hashtags' => ['#brand', '#content', '#social'],
                                    'recommended_day' => 'Sat',
                                    'recommended_hour' => 10,
                                    'inspired_by_post_ids' => [],
                                    'why' => 'Still images punched above usual last month.',
                                ],
                            ],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'GoodGym',
            'description' => 'Community fitness charity',
        ]);
        $rival = TrackedAccount::factory()->for($user)->create([
            'handle' => 'rivalgym',
            'is_own_account' => false,
        ]);

        $base = CarbonImmutable::now('Europe/London')->subDays(20);

        for ($i = 0; $i < 12; $i++) {
            Post::factory()->forAccount($rival)->create([
                'posted_at' => $base->addDays($i),
                'caption' => 'Training day #fitness #community',
                'metrics' => ['views' => 1000, 'likes' => 40, 'comments' => 4, 'shares' => 0],
            ]);
        }

        $winner = Post::factory()->forAccount($rival)->create([
            'posted_at' => $base->addDays(15),
            'caption' => 'Big session #fitness #london',
            'metrics' => ['views' => 8000, 'likes' => 320, 'comments' => 40, 'shares' => 5],
        ]);
        PostAnalysis::factory()->for($winner)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Open on the sweat',
        ]);

        $this->actingAs($user)
            ->post(route('brief.generate'))
            ->assertRedirect(route('brief.index'));

        $brief = WeeklyBrief::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($brief);
        $this->assertTrue($brief->was_free);
        $this->assertSame(0.0, (float) $brief->credits_charged_pence);
        $this->assertCount(3, $brief->ideas);
        $this->assertSame('Open on the proof', $brief->ideas->first()->hook);

        $this->actingAs($user)
            ->get(route('brief.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('brief/Index')
                ->where('brief.id', $brief->id)
                ->has('brief.ideas', 3)
                ->where('creditCost', fn ($value): bool => (float) $value === WeeklyBriefGenerator::CREDIT_PENCE)
            );
    }

    public function test_mark_idea_used_toggles(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $brief = WeeklyBrief::factory()->for($user)->create();
        $idea = $brief->ideas()->create([
            'position' => 1,
            'format' => 'Reel',
            'hook' => 'Hook',
            'caption_angle' => 'Angle',
            'cta' => 'CTA',
            'hashtags' => ['#a', '#b', '#c'],
            'recommended_day' => 'Mon',
            'recommended_hour' => 9,
            'inspired_by_post_ids' => [],
            'why' => 'Because numbers',
        ]);

        $this->actingAs($user)
            ->post(route('brief.ideas.used', $idea))
            ->assertRedirect();

        $this->assertNotNull($idea->fresh()->used_at);

        $this->actingAs($user)
            ->post(route('brief.ideas.used', $idea))
            ->assertRedirect();

        $this->assertNull($idea->fresh()->used_at);
    }
}
