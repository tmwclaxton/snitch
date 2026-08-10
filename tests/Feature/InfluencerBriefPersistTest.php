<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class InfluencerBriefPersistTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_generate_brief_persists_on_brand_profile(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.influencer_find.model' => 'test-model',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Find mid-size fashion creators for sneaker DTC collabs in English.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        BrandProfile::factory()->for($user)->create([
            'name' => 'Sneaker Co',
            'description' => 'DTC sneakers',
            'influencer_brief' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('influencers.brief'), [
                'platform' => 'instagram',
                'language' => 'English',
                'min_followers' => 1000,
                'max_followers' => 50000,
            ])
            ->assertOk()
            ->assertJsonPath('brief', 'Find mid-size fashion creators for sneaker DTC collabs in English.');

        $this->assertSame(
            'Find mid-size fashion creators for sneaker DTC collabs in English.',
            $user->fresh()->brandProfile?->influencer_brief,
        );
    }

    public function test_update_brief_persists_on_brand_profile(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'influencer_brief' => 'Old brief about creators.',
        ]);

        $this->actingAs($user)
            ->patchJson(route('influencers.brief.update'), [
                'influencer_brief' => 'Edited brief for micro creators in streetwear.',
            ])
            ->assertOk()
            ->assertJsonPath('brief', 'Edited brief for micro creators in streetwear.');

        $this->assertSame(
            'Edited brief for micro creators in streetwear.',
            $user->fresh()->brandProfile?->influencer_brief,
        );
    }

    public function test_update_brief_can_clear_stored_brief(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'influencer_brief' => 'Something to clear.',
        ]);

        $this->actingAs($user)
            ->patchJson(route('influencers.brief.update'), [
                'influencer_brief' => '   ',
            ])
            ->assertOk()
            ->assertJsonPath('brief', '');

        $this->assertNull($user->fresh()->brandProfile?->influencer_brief);
    }

    public function test_guest_cannot_update_brief(): void
    {
        $this->patchJson(route('influencers.brief.update'), [
            'influencer_brief' => 'Nope.',
        ])->assertUnauthorized();
    }
}
