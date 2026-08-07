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

    public function test_suggest_returns_polaroid_candidates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.suggest'), [
                'name' => 'Loaf Local',
                'description' => 'Neighborhood bakery content brand',
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Index')
                ->has('suggestions', 6)
            );
    }

    public function test_confirm_creates_tracked_accounts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.confirm'), [
                'name' => 'Loaf Local',
                'description' => 'Neighborhood bakery content brand',
                'suggestions' => [
                    [
                        'platform' => 'instagram',
                        'handle' => 'rivalbakery',
                        'url' => 'https://instagram.com/rivalbakery',
                        'display_name' => 'Rival Bakery',
                    ],
                ],
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertSame(1, TrackedAccount::query()->where('user_id', $user->id)->count());
        $this->assertInstanceOf(BrandProfile::class, $user->fresh()->brandProfile);
    }
}
