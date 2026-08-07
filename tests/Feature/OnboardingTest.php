<?php

namespace Tests\Feature;

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

    public function test_onboarding_page_renders_without_app_shell_props_for_suggestions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Index')
                ->missing('suggestions')
                ->has('platforms')
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
    }

    public function test_user_can_save_brand_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.store'), [
                'name' => 'Loaf Local',
                'website' => 'https://loaf.example',
                'description' => 'Neighborhood bakery content brand',
                'own_handles' => ['instagram' => '@loaf'],
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('brand_profiles', [
            'user_id' => $user->id,
            'name' => 'Loaf Local',
        ]);
    }

    public function test_user_can_save_brand_profile_with_website_missing_scheme(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.store'), [
                'name' => 'GrantGunner',
                'website' => 'www.grantgunner.org',
                'description' => 'We help startups find and apply for grants.',
                'own_handles' => [],
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('brand_profiles', [
            'user_id' => $user->id,
            'name' => 'GrantGunner',
            'website' => 'https://www.grantgunner.org',
        ]);
    }
}
