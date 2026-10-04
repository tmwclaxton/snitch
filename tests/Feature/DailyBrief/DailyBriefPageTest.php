<?php

namespace Tests\Feature\DailyBrief;

use App\Models\BrandProfile;
use App\Models\DailyBrief;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DailyBriefPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snitch.admin_emails' => ['admin@snitch.test'],
        ]);
    }

    public function test_today_page_renders_empty_state(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/Index')
                ->where('brief', null)
                ->where('canRegenerate', false)
                ->has('date')
            );
    }

    public function test_today_page_renders_ready_brief(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        DailyBrief::factory()->for($user)->create([
            'headline' => 'Post a Reel tonight.',
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/Index')
                ->where('brief.headline', 'Post a Reel tonight.')
                ->has('history', 1)
            );
    }

    public function test_admin_can_regenerate_non_admin_cannot(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@snitch.test',
            'daily_brief_enabled' => true,
        ]);
        BrandProfile::factory()->for($admin)->create();
        DailyBrief::factory()->for($admin)->create();

        $this->actingAs($admin)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canRegenerate', true));

        $customer = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($customer)->create();

        $this->actingAs($customer)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canRegenerate', false));

        $this->actingAs($customer)
            ->post(route('today.generate'))
            ->assertForbidden();
    }

    public function test_toggle_action_marks_done(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $brief = DailyBrief::factory()->for($user)->create([
            'payload' => [
                'headline' => 'Do the thing',
                'actions' => [
                    ['title' => 'Post tonight', 'why' => 'why', 'how' => 'how', 'done_at' => null],
                ],
                'big_numbers' => [],
                'watch' => [],
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('today.actions.toggle', ['brief' => $brief->id, 'index' => 0]))
            ->assertRedirect();

        $this->assertNotNull($brief->fresh()->payload['actions'][0]['done_at']);

        $this->actingAs($user)
            ->patch(route('today.actions.toggle', ['brief' => $brief->id, 'index' => 0]))
            ->assertRedirect();

        $this->assertNull($brief->fresh()->payload['actions'][0]['done_at']);
    }
}
