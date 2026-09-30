<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Apify\PlatformAdapterManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_brand_is_redirected_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_onboarding_page_renders_competitor_step(): void
    {
        $user = User::factory()->withoutPlatformSubscription()->create();

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Index')
                ->where('step', 'competitors')
                ->has('platforms')
                ->has('trialDays')
                ->has('trialCompetitorLimit')
            );
    }

    public function test_onboarding_uses_minimal_public_chrome(): void
    {
        $page = file_get_contents(resource_path('js/pages/onboarding/Index.vue'));
        $nav = file_get_contents(resource_path('js/components/marketing/PublicNav.vue'));
        $layout = file_get_contents(resource_path('js/layouts/PublicLayout.vue'));

        $this->assertNotFalse($page, 'Missing onboarding/Index.vue source');
        $this->assertNotFalse($nav, 'Missing PublicNav.vue source');
        $this->assertNotFalse($layout, 'Missing PublicLayout.vue source');

        $this->assertStringContainsString('setLayoutProps({ minimal: true })', $page);
        $this->assertStringContainsString('minimal?: boolean', $nav);
        $this->assertStringContainsString('v-if="!minimal"', $nav);
        $this->assertStringContainsString('Dashboard', $nav);
        $this->assertStringContainsString('Log out', $nav);
        $this->assertStringContainsString(':minimal="minimal"', $layout);
        $this->assertStringContainsString('PublicFooter v-if="!minimal"', $layout);
        $this->assertStringContainsString('Add competitors', $page);
        $this->assertStringContainsString('The reveal', $page);
        $this->assertStringContainsString('Start your free trial', $page);
        $this->assertStringNotContainsString('Tell Snitch about your brand', $page);
    }

    public function test_user_can_save_competitors_and_reach_reveal(): void
    {
        $user = User::factory()->withoutPlatformSubscription()->create();
        TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalbakery',
            'display_name' => 'Rival Bakery',
            'followers' => 1200,
        ]);

        $this->actingAs($user)
            ->post(route('onboarding.store'), [
                'own_handle' => 'loaflocal',
                'competitors' => [
                    [
                        'platform' => 'instagram',
                        'handle' => 'rivalbakery',
                        'display_name' => 'Rival Bakery',
                        'avatar' => null,
                        'followers' => 1200,
                    ],
                ],
            ])
            ->assertRedirect(route('onboarding.show', ['step' => 'reveal']));

        $this->assertDatabaseHas('brand_profiles', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'rivalbakery',
        ]);

        $brand = BrandProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('@loaflocal', $brand->own_handles['instagram'] ?? null);

        $this->actingAs($user)
            ->get(route('onboarding.show', ['step' => 'reveal']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Index')
                ->where('step', 'reveal')
                ->has('trackedBy')
            );
    }

    public function test_onboarding_skips_when_user_has_competitors_and_subscription(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_search_returns_corpus_accounts(): void
    {
        $user = User::factory()->withoutPlatformSubscription()->create();
        TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'searchablebrand',
            'display_name' => 'Searchable Brand',
            'followers' => 5000,
        ]);

        $this->actingAs($user)
            ->getJson(route('onboarding.search', ['q' => 'searchable']))
            ->assertOk()
            ->assertJsonFragment([
                'handle' => 'searchablebrand',
                'followers' => 5000,
            ]);
    }

    public function test_lookup_uses_platform_adapter_when_missing(): void
    {
        $user = User::factory()->withoutPlatformSubscription()->create();

        $adapter = Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('resolveProfile')
            ->once()
            ->with('@freshlookup')
            ->andReturn([
                'handle' => 'freshlookup',
                'display_name' => 'Fresh Lookup',
                'avatar' => 'https://example.com/a.jpg',
                'followers' => 99,
                'url' => 'https://www.instagram.com/freshlookup/',
            ]);

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->andReturn($adapter);
        $this->app->instance(PlatformAdapterManager::class, $manager);

        $this->actingAs($user)
            ->postJson(route('onboarding.lookup'), [
                'q' => '@freshlookup',
                'platform' => 'instagram',
            ])
            ->assertOk()
            ->assertJsonPath('result.handle', 'freshlookup')
            ->assertJsonPath('result.source', 'live');
    }

    public function test_continue_sends_unsubscribed_user_to_paywall(): void
    {
        $user = User::factory()->withoutPlatformSubscription()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@loaf'],
        ]);
        TrackedAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('onboarding.continue'))
            ->assertRedirect(route('onboarding.show', ['step' => 'paywall']));

        $this->actingAs($user)
            ->get(route('onboarding.show', ['step' => 'paywall']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Index')
                ->where('step', 'paywall')
            );
    }

    public function test_admin_and_user_one_bypass_paywall_gate(): void
    {
        config(['snitch.admin_emails' => ['admin@snitch.test']]);

        $admin = User::factory()->withoutPlatformSubscription()->create([
            'email' => 'admin@snitch.test',
        ]);
        BrandProfile::factory()->for($admin)->create();
        TrackedAccount::factory()->for($admin)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
