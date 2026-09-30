<?php

namespace Tests\Feature\Features;

use App\Models\BrandProfile;
use App\Models\FeatureSuggestion;
use App\Models\FeatureVote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeatureSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_starter_roadmap_ideas(): void
    {
        $titles = FeatureSuggestion::query()->orderBy('id')->pluck('title')->all();

        $this->assertSame([
            'Weekly email digest',
            'TikTok tracking',
            'Alerts when a rival post goes viral',
            'Competitor suggestions',
        ], $titles);
    }

    public function test_dashboard_lists_suggestions_sorted_by_votes(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $low = FeatureSuggestion::factory()->create([
            'title' => 'Low votes idea',
            'user_id' => null,
        ]);
        $high = FeatureSuggestion::factory()->create([
            'title' => 'High votes idea',
            'user_id' => null,
        ]);

        FeatureVote::factory()->count(3)->create(['suggestion_id' => $high->id]);
        FeatureVote::factory()->create(['suggestion_id' => $low->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('featureSuggestions')
                ->where('featureSuggestions.0.title', 'High votes idea')
                ->where('featureSuggestions.0.votes_count', 3)
                ->where('featureSuggestions.0.voted', false)
            );
    }

    public function test_user_can_submit_idea_and_toggle_vote(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestion = FeatureSuggestion::factory()->create([
            'title' => 'Toggle me',
            'user_id' => null,
        ]);

        $this->actingAs($user)
            ->post(route('features.store'), [
                'title' => 'My new idea',
                'body' => 'Please add dark mode for the feed board.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('feature_suggestions', [
            'title' => 'My new idea',
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $created = FeatureSuggestion::query()->where('title', 'My new idea')->firstOrFail();
        $this->assertDatabaseHas('feature_votes', [
            'user_id' => $user->id,
            'suggestion_id' => $created->id,
        ]);

        $this->actingAs($user)
            ->post(route('features.vote', $suggestion))
            ->assertRedirect();

        $this->assertDatabaseHas('feature_votes', [
            'user_id' => $user->id,
            'suggestion_id' => $suggestion->id,
        ]);

        $this->actingAs($user)
            ->post(route('features.vote', $suggestion))
            ->assertRedirect();

        $this->assertDatabaseMissing('feature_votes', [
            'user_id' => $user->id,
            'suggestion_id' => $suggestion->id,
        ]);
    }

    public function test_admin_can_update_feature_status(): void
    {
        config(['snitch.admin_emails' => ['admin@snitch.test']]);

        $admin = User::factory()->create(['email' => 'admin@snitch.test']);
        $suggestion = FeatureSuggestion::factory()->create([
            'status' => 'open',
            'user_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.features.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Features')
                ->has('suggestions')
                ->has('statuses')
            );

        $this->actingAs($admin)
            ->patch(route('admin.features.update', $suggestion), [
                'status' => 'building',
            ])
            ->assertRedirect();

        $this->assertSame('building', $suggestion->fresh()->status);
    }

    public function test_non_admin_cannot_update_feature_status(): void
    {
        config(['snitch.admin_emails' => ['admin@snitch.test']]);

        $user = User::factory()->create(['email' => 'user@snitch.test']);
        $suggestion = FeatureSuggestion::factory()->create(['status' => 'open']);

        $this->actingAs($user)
            ->patch(route('admin.features.update', $suggestion), [
                'status' => 'shipped',
            ])
            ->assertForbidden();
    }
}
