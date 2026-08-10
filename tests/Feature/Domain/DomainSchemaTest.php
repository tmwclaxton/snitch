<?php

namespace Tests\Feature\Domain;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Models\WinnerRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_create_user_with_brand_accounts_posts_and_analysis(): void
    {
        $user = User::factory()->create();

        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'Snitch Bakery',
        ]);

        $accounts = TrackedAccount::factory()
            ->count(2)
            ->for($user)
            ->sequence(
                ['platform' => Platform::Instagram, 'handle' => 'rival_one'],
                ['platform' => Platform::TikTok, 'handle' => 'rival_two'],
            )
            ->create();

        $posts = $accounts->map(function (TrackedAccount $account) {
            return Post::factory()
                ->forAccount($account)
                ->create([
                    'type' => PostType::Reel,
                ]);
        });

        $analyses = $posts->map(function (Post $post) {
            return PostAnalysis::factory()->for($post)->create([
                'status' => AnalysisStatus::Completed,
                'hook_window_end_sec' => 3,
            ]);
        });

        WinnerRule::factory()->for($user)->create([
            'preset' => 'balanced',
        ]);

        WinnerInsight::factory()
            ->forPost($posts->first())
            ->create([
                'score' => 88.5,
            ]);

        $this->assertDatabaseCount('brand_profiles', 1);
        $this->assertDatabaseCount('tracked_accounts', 2);
        $this->assertDatabaseCount('posts', 2);
        $this->assertDatabaseCount('post_analyses', 2);
        $this->assertDatabaseCount('winner_rules', 1);
        $this->assertDatabaseCount('winner_insights', 1);

        $this->assertTrue($user->brandProfile->is($brand));
        $this->assertCount(2, $user->trackedAccounts);
        $this->assertSame('Snitch Bakery', $user->fresh()->brandProfile->name);
        $this->assertSame(AnalysisStatus::Completed, $analyses->first()->status);
        $this->assertSame(Platform::Instagram, $posts->first()->platform);
        $this->assertTrue($posts->first()->analysis->is($analyses->first()));
        $this->assertNotNull($user->winnerRule);
        $this->assertSame(88.5, $posts->first()->winnerInsight->score);
    }

    public function test_tracked_account_policy_allows_only_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = TrackedAccount::factory()->for($owner)->create();

        $this->assertTrue($owner->can('view', $account));
        $this->assertTrue($owner->can('update', $account));
        $this->assertTrue($owner->can('delete', $account));
        $this->assertFalse($other->can('view', $account));
        $this->assertFalse($other->can('update', $account));
        $this->assertFalse($other->can('delete', $account));
    }

    public function test_post_policy_allows_trackers_and_completed_corpus_reels(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = TrackedAccount::factory()->for($owner)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);

        $this->assertTrue($owner->can('view', $post));
        $this->assertFalse($other->can('view', $post));

        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $post->unsetRelation('analysis');

        $this->assertTrue($other->can('view', $post->fresh('analysis')));
    }
}
