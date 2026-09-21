<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetitorInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_snitch_show_defers_insights_and_exposes_followers(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'followers' => 12400,
            'handle' => 'rivalbakery',
        ]);
        Post::factory()->forAccount($account)->create([
            'caption' => 'Hello #bakery friends',
            'metrics' => [
                'views' => 200,
                'likes' => 20,
                'comments' => 2,
                'shares' => 0,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('competitors.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Show')
                ->where('account.followers', 12400)
                ->missing('insights')
                ->loadDeferredProps('insights', fn (Assert $page) => $page
                    ->where('insights.engagement.posts', 1)
                    ->where('insights.hashtags.0.term', 'bakery')
                    ->has('insights.activity.heatmap')
                    ->has('insights.keywords')
                )
            );

        $showVue = file_get_contents(resource_path('js/pages/competitors/Show.vue'));
        $indexVue = file_get_contents(resource_path('js/pages/competitors/Index.vue'));

        $this->assertIsString($showVue);
        $this->assertIsString($indexVue);
        $this->assertStringContainsString('How they post', $showVue);
        $this->assertStringContainsString('FormatMixChart', $showVue);
        $this->assertStringContainsString('snitch-contact-sheet-rows', $showVue);
        $this->assertStringContainsString('Math.ceil(count / 2)', $showVue);
        $this->assertStringContainsString('snitch-glance-tag', $showVue);
        $this->assertStringNotContainsString('space-y-1 text-sm', $showVue);
        $this->assertStringContainsString('formatFollowers(account.followers)', $showVue);
        $this->assertStringContainsString('Followers', $indexVue);
    }
}
