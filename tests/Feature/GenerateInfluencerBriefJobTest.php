<?php

namespace Tests\Feature;

use App\Jobs\GenerateInfluencerBriefJob;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class GenerateInfluencerBriefJobTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_onboarding_dispatches_influencer_brief_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.store'), [
                'name' => 'Loaf Local',
                'website' => 'https://loaf.example',
                'description' => 'Neighborhood bakery content brand',
                'own_handles' => ['instagram' => '@loaf'],
            ])
            ->assertRedirect(route('competitors.index'));

        Queue::assertPushed(GenerateInfluencerBriefJob::class, function (GenerateInfluencerBriefJob $job) use ($user): bool {
            return $job->userId === $user->id;
        });
    }

    public function test_job_persists_generated_brief_on_brand(): void
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
                            'content' => 'Find mid-size bakery creators who post warm neighborhood food Reels in English.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        BrandProfile::factory()->for($user)->create([
            'name' => 'Loaf Local',
            'description' => 'Neighborhood bakery',
            'influencer_brief' => null,
        ]);

        (new GenerateInfluencerBriefJob($user->id))->handle(
            app(InfluencerDiscoveryService::class),
            app(VendorUsageCharger::class),
            app(UsageBillingService::class),
        );

        $user->brandProfile?->refresh();

        $this->assertNotNull($user->brandProfile?->influencer_brief);
        $this->assertStringContainsString('bakery', strtolower((string) $user->brandProfile?->influencer_brief));
    }

    public function test_job_skips_when_brief_already_present(): void
    {
        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        BrandProfile::factory()->for($user)->create([
            'influencer_brief' => 'Existing brief about creators.',
        ]);

        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->never())->method('chat');

        $this->app->instance(NanoGptClient::class, $nano);

        (new GenerateInfluencerBriefJob($user->id))->handle(
            app(InfluencerDiscoveryService::class),
            app(VendorUsageCharger::class),
            app(UsageBillingService::class),
        );

        $this->assertSame('Existing brief about creators.', $user->fresh()->brandProfile?->influencer_brief);
    }

    public function test_influencers_page_uses_default_filters_and_brand_brief(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'Sneaker Co',
            'influencer_brief' => 'Find sneaker creators for DTC collabs.',
        ]);

        $this->actingAs($user)
            ->get(route('influencers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('influencers/Index')
                ->where('filters.platform', 'instagram')
                ->where('filters.language', 'English')
                ->where('filters.min_followers', 1000)
                ->where('filters.max_followers', 50000)
                ->where('filters.brief', 'Find sneaker creators for DTC collabs.')
            );
    }
}
