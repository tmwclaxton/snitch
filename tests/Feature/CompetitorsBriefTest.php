<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class CompetitorsBriefTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_user_can_update_competitor_brief(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'competitor_brief' => null,
        ]);

        $this->actingAs($user)
            ->patchJson(route('competitors.brief.update'), [
                'competitor_brief' => 'Track social listening SaaS rivals on TikTok.',
            ])
            ->assertOk()
            ->assertJsonPath('brief', 'Track social listening SaaS rivals on TikTok.');

        $this->assertSame(
            'Track social listening SaaS rivals on TikTok.',
            $user->fresh()->brandProfile?->competitor_brief,
        );
    }

    public function test_user_can_generate_competitor_brief(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Find social listening SaaS competitors active on TikTok and Instagram.',
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        BrandProfile::factory()->for($user)->create([
            'name' => 'Snitch',
            'description' => 'Social media intelligence platform',
            'competitor_brief' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('competitors.brief'), [
                'platforms' => ['tiktok', 'instagram'],
            ])
            ->assertOk()
            ->assertJsonPath(
                'brief',
                'Find social listening SaaS competitors active on TikTok and Instagram.',
            );

        $this->assertSame(
            'Find social listening SaaS competitors active on TikTok and Instagram.',
            $user->fresh()->brandProfile?->competitor_brief,
        );
    }
}
