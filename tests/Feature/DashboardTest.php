<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_executive_brief(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ExecutiveBrief')
                ->has('brief')
                ->missing('panels')
                ->missing('featureSuggestions')
            );
    }

    public function test_executive_brief_empty_state_when_rivals_exist_but_no_brief(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalbakery',
            'followers' => 1200,
        ]);
        Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['likes' => 10, 'comments' => 2],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ExecutiveBrief')
                ->where('brief.empty', true)
                ->missing('rivals')
                ->missing('panels')
            );
    }

    public function test_executive_brief_vue_is_memo_not_panel_canvas(): void
    {
        $page = file_get_contents(resource_path('js/pages/ExecutiveBrief.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertNotFalse($page);
        $this->assertNotFalse($sidebar);
        $this->assertStringContainsString('Executive Brief', $page);
        $this->assertStringContainsString('max-w-none', $page);
        $this->assertStringNotContainsString('PanelBigNumber', $page);
        $this->assertStringNotContainsString('TrackedBySection', $page);
        $this->assertStringNotContainsString('VoteSection', $page);
        $this->assertStringContainsString("title: 'Executive Brief'", $sidebar);
    }

    public function test_paywalled_users_see_empty_executive_brief(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        // No subscription / credits: product access blocked for non-admins.
        $user->forceFill(['trial_ends_at' => null])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
