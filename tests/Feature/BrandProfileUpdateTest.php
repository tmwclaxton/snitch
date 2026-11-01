<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_page_is_displayed(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'Loaf Local',
            'website' => 'https://loaf.example',
            'description' => 'Neighborhood bakery',
            'own_handles' => ['instagram' => '@loaf'],
        ]);

        $this->actingAs($user)
            ->get(route('brand.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('brand/Index')
                ->where('brand.name', 'Loaf Local')
                ->where('brand.website', 'https://loaf.example')
                ->where('brand.own_handles.instagram', '@loaf')
                ->has('platforms')
            );
    }

    public function test_user_without_brand_is_redirected_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('brand.edit'))
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_brand_profile_can_be_updated(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'Old Name',
            'website' => 'https://old.example',
            'description' => 'Old description',
            'own_handles' => ['instagram' => '@old'],
        ]);

        $this->actingAs($user)
            ->put(route('brand.update'), [
                'name' => 'Loaf Local',
                'website' => 'www.loaf.example',
                'description' => 'Neighborhood bakery content brand',
                'own_handles' => [
                    'instagram' => '@loaf',
                    'tiktok' => '@loaflocal',
                    'facebook' => '',
                    'linkedin' => '',
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('brand.edit'));

        $this->assertDatabaseHas('brand_profiles', [
            'user_id' => $user->id,
            'name' => 'Loaf Local',
            'website' => 'https://www.loaf.example',
            'description' => 'Neighborhood bakery content brand',
        ]);

        $handles = $user->fresh()->brandProfile?->own_handles;

        $this->assertIsArray($handles);
        $this->assertSame('@loaf', $handles['instagram'] ?? null);
        $this->assertSame('@loaflocal', $handles['tiktok'] ?? null);
    }

    public function test_brand_update_requires_name_and_description(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('brand.edit'))
            ->put(route('brand.update'), [
                'name' => '',
                'website' => 'https://loaf.example',
                'description' => '',
                'own_handles' => [],
            ])
            ->assertSessionHasErrors(['name', 'description'])
            ->assertRedirect(route('brand.edit'));
    }

    public function test_brand_profile_can_be_updated_without_website(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'Old Name',
            'website' => 'https://old.example',
            'description' => 'Old description',
        ]);

        $this->actingAs($user)
            ->put(route('brand.update'), [
                'name' => 'Loaf Local',
                'website' => '',
                'description' => 'Neighborhood bakery content brand',
                'own_handles' => [
                    'instagram' => '@loaf',
                    'tiktok' => '',
                    'facebook' => '',
                    'linkedin' => '',
                    'youtube' => '',
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('brand.edit'));

        $this->assertDatabaseHas('brand_profiles', [
            'user_id' => $user->id,
            'name' => 'Loaf Local',
            'website' => null,
            'description' => 'Neighborhood bakery content brand',
        ]);
    }

    public function test_brand_profile_can_be_updated_with_null_website(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'website' => 'https://old.example',
        ]);

        $this->actingAs($user)
            ->put(route('brand.update'), [
                'name' => 'Loaf Local',
                'website' => null,
                'description' => 'Neighborhood bakery content brand',
                'own_handles' => [],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('brand.edit'));

        $this->assertDatabaseHas('brand_profiles', [
            'user_id' => $user->id,
            'name' => 'Loaf Local',
            'website' => null,
        ]);
    }

    public function test_sidebar_includes_brand_link(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertNotFalse($sidebar);
        $this->assertStringContainsString("title: 'Brand'", $sidebar);
        $this->assertStringContainsString('BrandProfileController', $sidebar);
    }

    public function test_settings_nav_does_not_include_brand_or_winners(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/settings/Layout.vue'));

        $this->assertNotFalse($layout);
        $this->assertStringNotContainsString("title: 'Brand'", $layout);
        $this->assertStringNotContainsString("title: 'Winners'", $layout);
        $this->assertStringNotContainsString('WinnerRuleController', $layout);
        $this->assertStringNotContainsString('BrandProfileController', $layout);
    }

    public function test_brand_form_component_keeps_website_first_with_autofill(): void
    {
        $form = file_get_contents(resource_path('js/components/BrandProfileForm.vue'));

        $this->assertNotFalse($form);
        $this->assertStringContainsString('Autofill from website', $form);
        $this->assertStringContainsString('snitch-field-prefix', $form);
        $this->assertStringContainsString('https://', $form);
        $this->assertStringContainsString('www.yourbrand.com', $form);
        $this->assertStringContainsString('(optional)', $form);
        $this->assertStringContainsString('field-sizing-content', $form);
        $this->assertStringContainsString("website: website === '' ? null : website", $form);
        $this->assertDoesNotMatchRegularExpression(
            '/id="brand-website"[^>]*\brequired\b/',
            $form,
        );
        $this->assertTrue(
            strpos($form, 'Website') < strpos($form, 'Brand name'),
            'Website field should appear before brand name',
        );
    }
}
