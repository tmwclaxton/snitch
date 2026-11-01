<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_onboarding_page_renders_brand_step(): void
    {
        $user = User::factory()->withoutPlatformSubscription()->create();

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Index')
                ->where('step', 'brand')
                ->has('platforms')
                ->has('trialDays')
                ->has('platformFeePence')
                ->missing('trackedBy')
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
        $this->assertStringContainsString('Tell Snitch about your brand', $page);
        $this->assertStringContainsString('BrandProfileForm', $page);
        $this->assertStringContainsString('Start your free trial', $page);
        $this->assertStringNotContainsString('Add competitors', $page);
        $this->assertStringNotContainsString('The reveal', $page);
        $this->assertStringNotContainsString('onboarding-search', $page);
    }

    public function test_user_can_save_brand_and_reach_paywall(): void
    {
        $user = User::factory()->withoutPlatformSubscription()->create();

        $this->actingAs($user)
            ->post(route('onboarding.store'), [
                'name' => 'Loaf Local',
                'website' => 'https://loaf.example',
                'description' => 'Neighborhood bakery',
                'own_handles' => [
                    'instagram' => '@loaflocal',
                ],
            ])
            ->assertRedirect(route('onboarding.show', ['step' => 'paywall']));

        $this->assertDatabaseHas('brand_profiles', [
            'user_id' => $user->id,
            'name' => 'Loaf Local',
            'website' => 'https://loaf.example',
            'description' => 'Neighborhood bakery',
        ]);

        $brand = BrandProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('@loaflocal', $brand->own_handles['instagram'] ?? null);
        $this->assertSame(0, $user->trackedAccounts()->count());

        $this->actingAs($user)
            ->get(route('onboarding.show', ['step' => 'paywall']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Index')
                ->where('step', 'paywall')
                ->missing('trackedBy')
            );
    }

    public function test_subscribed_user_leaves_onboarding_for_tracking(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.store'), [
                'name' => 'Loaf Local',
                'website' => 'https://loaf.example',
                'description' => 'Neighborhood bakery',
                'own_handles' => [
                    'instagram' => '@loaflocal',
                ],
            ])
            ->assertRedirect(route('competitors.index'));
    }

    public function test_onboarding_skips_when_user_has_brand_and_subscription(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_brand_without_subscription_opens_paywall_without_competitors(): void
    {
        $user = User::factory()->withoutPlatformSubscription()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.show', ['step' => 'paywall']));
    }

    public function test_admin_and_user_one_bypass_paywall_gate(): void
    {
        config(['snitch.admin_emails' => ['admin@snitch.test']]);

        $admin = User::factory()->withoutPlatformSubscription()->create([
            'email' => 'admin@snitch.test',
        ]);
        BrandProfile::factory()->for($admin)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
