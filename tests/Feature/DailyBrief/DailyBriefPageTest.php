<?php

namespace Tests\Feature\DailyBrief;

use App\Models\BrandProfile;
use App\Models\DailyBrief;
use App\Models\User;
use Carbon\CarbonImmutable;
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
                ->where('brief.big_numbers.1.value', 'New')
                ->where('brief.big_numbers.1.note', fn (mixed $note): bool => is_string($note)
                    && str_contains($note, 'Daily tracking started')
                    && str_contains($note, 'first comparison tomorrow'))
            );
    }

    public function test_today_page_sanitizes_stored_post_ids_and_borrowed_names(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $brief = DailyBrief::factory()->for($user)->create([
            'headline' => 'Post a Reel tonight.',
            'facts' => [
                'allowed_handles' => ['goodgym', 'letsgosocialuk'],
                'allowed_post_ids' => [218],
                'borrowed_competitor_names' => ['Jessie'],
                'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
                'top_competitor_hit_24h' => [
                    'post_id' => 218,
                    'handle' => 'goodgym',
                    'format' => 'Reel',
                    'hook' => 'Living Room Listens',
                    'times_usual' => 1.0,
                ],
                'competitors' => [],
            ],
            'payload' => [
                'headline' => 'Post a Reel tonight.',
                'big_numbers' => [
                    ['label' => 'Followers', 'value' => '98', 'note' => null],
                    ['label' => 'Change since yesterday', 'value' => 'New', 'note' => 'Daily tracking started 7 Oct; first comparison tomorrow'],
                    ['label' => 'Your posts this week', 'value' => '1', 'note' => null],
                    ['label' => 'Competitor posts in the last day', 'value' => '0', 'note' => null],
                ],
                'actions' => [
                    [
                        'title' => 'Comment',
                        'why' => 'why',
                        'how' => 'Reply under (post_id 218)',
                        'related_handles' => ['goodgym'],
                        'related_post_ids' => [218],
                    ],
                ],
                'unused_weekly_ideas' => [
                    ['position' => 1, 'format' => 'Reel', 'hook' => "Jessie\u{2019}s story: nervous, alone, now a regular"],
                ],
                'watch' => [],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/Index')
                ->where('brief.actions.0.how', fn (mixed $how): bool => is_string($how)
                    && str_contains($how, 'Living Room Listens')
                    && ! str_contains($how, 'post_id'))
                ->where('brief.unused_weekly_ideas.0.hook', fn (mixed $hook): bool => is_string($hook)
                    && str_contains($hook, "member's story")
                    && ! str_contains($hook, 'Jessie'))
            );

        $brief->refresh();
        $this->assertStringContainsString('Living Room Listens', (string) ($brief->payload['actions'][0]['how'] ?? ''));
        $this->assertStringNotContainsString('Jessie', (string) ($brief->payload['unused_weekly_ideas'][0]['hook'] ?? ''));
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

    public function test_today_rewrites_legacy_change_since_yesterday_copy(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Europe/London'));

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        DailyBrief::factory()->for($user)->create([
            'brief_date' => '2026-10-04',
            'payload' => [
                'headline' => 'Post a Reel tonight.',
                'big_numbers' => [
                    ['label' => 'Followers', 'value' => '98', 'note' => null],
                    ['label' => 'Change since yesterday', 'value' => 'Daily tracking starts today', 'note' => 'Daily tracking starts today'],
                ],
                'actions' => [],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/Index')
                ->where('brief.big_numbers.1.value', 'New')
                ->where('brief.big_numbers.1.note', 'Daily tracking started 4 Oct; first comparison tomorrow')
            );

        $today = file_get_contents(resource_path('js/pages/today/Index.vue'));
        $this->assertIsString($today);
        $this->assertStringContainsString('Action points', $today);
        $this->assertStringNotContainsString('Action <span class="snitch-highlight">points</span>', $today);
    }
}
