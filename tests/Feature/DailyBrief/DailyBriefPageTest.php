<?php

namespace Tests\Feature\DailyBrief;

use App\Models\BrandProfile;
use App\Models\DailyBrief;
use App\Models\User;
use App\Support\DailyBriefPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->get('/today')
            ->assertRedirect('/dashboard');

        $this->assertFileDoesNotExist(resource_path('js/pages/today/Index.vue'));
    }

    public function test_today_page_renders_ready_brief(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        DailyBrief::factory()->for($user)->create([
            'headline' => 'Post a Reel tonight.',
        ]);

        $this->actingAs($user)
            ->get('/today')
            ->assertRedirect('/dashboard');
    }

    public function test_today_page_does_not_mutate_stored_brief_on_display(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $brief = DailyBrief::factory()->for($user)->create([
            'headline' => 'Post a Reel tonight.',
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
                    ['position' => 1, 'format' => 'Reel', 'hook' => "Jessie's story: nervous, alone, now a regular"],
                ],
                'watch' => [],
            ],
        ]);

        $presented = app(DailyBriefPresenter::class)->payload($brief);

        $this->assertSame('Reply under (post_id 218)', $presented['actions'][0]['how']);
        $this->assertSame("Jessie's story: nervous, alone, now a regular", $presented['unused_weekly_ideas'][0]['hook']);

        $brief->refresh();
        $this->assertSame('Reply under (post_id 218)', $brief->payload['actions'][0]['how'] ?? null);
        $this->assertSame("Jessie's story: nervous, alone, now a regular", $brief->payload['unused_weekly_ideas'][0]['hook'] ?? null);
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
            ->get('/today')
            ->assertRedirect('/dashboard');

        $customer = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($customer)->create();

        $this->actingAs($customer)
            ->get('/today')
            ->assertRedirect('/dashboard');

        $this->actingAs($customer)
            ->post('/today/generate')
            ->assertNotFound();
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
            ->patch('/today/'.$brief->id.'/actions/0')
            ->assertNotFound();

        $this->assertNull($brief->fresh()->payload['actions'][0]['done_at']);
    }

    public function test_today_rewrites_legacy_change_since_yesterday_copy(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Europe/London'));

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $brief = DailyBrief::factory()->for($user)->create([
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

        $presented = app(DailyBriefPresenter::class)->payload($brief);

        $this->assertSame('New', $presented['big_numbers'][1]['value']);
        $this->assertSame('Daily tracking started 4 Oct; first comparison tomorrow', $presented['big_numbers'][1]['note']);
        $this->assertFileDoesNotExist(resource_path('js/pages/today/Index.vue'));
    }
}
