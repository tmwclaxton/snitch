<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\DashboardPanel;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\Panels\DashboardPanelSchema;
use App\Services\Dashboard\Panels\DashboardPanelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPanelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_service_still_builds_defaults_while_page_is_executive_brief(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownclub',
            'followers' => 1500,
            'is_own_account' => true,
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 1400,
            'captured_on' => now()->subDays(7)->toDateString(),
        ]);
        Post::factory()->forAccount($own)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['likes' => 40, 'comments' => 3, 'views' => 900],
        ]);

        $resolved = app(DashboardPanelService::class)->forDashboard($user);
        $this->assertSame('default', $resolved['source']);
        $this->assertNotEmpty($resolved['panels']);
        $this->assertSame('big_number', $resolved['panels'][0]['type'] ?? null);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ExecutiveBrief')
                ->has('brief')
                ->missing('panels')
            );
    }

    public function test_stored_panels_remain_readable_via_service(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        DashboardPanel::factory()->for($user)->create([
            'position' => 0,
            'type' => DashboardPanel::TYPE_BIG_NUMBER,
            'title' => 'Followers',
            'payload' => ['label' => 'Followers', 'value' => '2,000'],
            'source' => 'curator',
        ]);
        DashboardPanel::factory()->for($user)->create([
            'position' => 1,
            'type' => DashboardPanel::TYPE_RANKED_LIST,
            'title' => 'Empty list',
            'payload' => ['items' => []],
            'source' => 'curator',
        ]);

        $resolved = app(DashboardPanelService::class)->forDashboard($user);
        $this->assertSame('stored', $resolved['source']);
        $this->assertCount(1, $resolved['panels']);
        $this->assertSame('Followers', $resolved['panels'][0]['title'] ?? null);
    }

    public function test_set_panels_validates_schema(): void
    {
        $user = User::factory()->create();
        $service = app(DashboardPanelService::class);

        $this->expectException(ValidationException::class);

        $service->setForUser($user, [
            [
                'type' => 'not_a_real_type',
                'title' => 'Bad',
                'payload' => [],
            ],
        ]);
    }

    public function test_set_panels_persists_valid_rows(): void
    {
        $user = User::factory()->create();
        $service = app(DashboardPanelService::class);

        $service->setForUser($user, [
            [
                'type' => DashboardPanel::TYPE_NOTE,
                'title' => 'Today',
                'payload' => [
                    'body' => 'Post a Reel tonight.',
                    'action_label' => 'Open Today',
                    'action_href' => '/today',
                ],
                'source' => 'curator',
            ],
        ]);

        $this->assertDatabaseCount('dashboard_panels', 1);
        $this->assertSame('note', DashboardPanel::query()->first()?->type);

        $schema = app(DashboardPanelSchema::class);
        $this->assertTrue($schema->hasData([
            'type' => DashboardPanel::TYPE_NOTE,
            'payload' => ['body' => 'Post a Reel tonight.'],
        ]));
    }
}
