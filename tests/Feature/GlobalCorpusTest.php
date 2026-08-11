<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Enums\TrackedAccountKind;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\SocialAccounts\SocialAccountResolver;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GlobalCorpusTest extends TestCase
{
    use RefreshDatabase;

    public function test_globalize_posts_migration_uses_count_having_raw_for_pgsql(): void
    {
        $source = (string) file_get_contents(database_path(
            'migrations/2026_08_10_220651_create_social_accounts_and_globalize_posts_table.php'
        ));

        $this->assertMatchesRegularExpression("/->havingRaw\\('COUNT\\(\\*\\) > 1'\\)/", $source);
        $this->assertDoesNotMatchRegularExpression("/->having\\('aggregate'/", $source);
    }

    public function test_removing_tracked_account_keeps_global_posts_and_analyses(): void
    {
        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rival_keep',
            'external_id' => 'ext-keep-1',
        ]);
        $socialId = $account->social_account_id;

        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'external_id' => 'reel-keep-1',
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_analyses', 1);

        $account->delete();

        $this->assertDatabaseMissing('tracked_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('social_accounts', ['id' => $socialId]);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'social_account_id' => $socialId,
        ]);
        $this->assertDatabaseCount('post_analyses', 1);
    }

    public function test_two_users_tracking_same_handle_share_one_social_account_and_posts(): void
    {
        $resolver = app(SocialAccountResolver::class);
        $social = $resolver->resolve(
            Platform::TikTok,
            'shared_creator',
            'ext-shared-9',
            ['display_name' => 'Shared Creator'],
        );

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $trackA = TrackedAccount::factory()->for($userA)->forSocialAccount($social)->create([
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $trackB = TrackedAccount::factory()->for($userB)->forSocialAccount($social)->create([
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->assertSame($trackA->social_account_id, $trackB->social_account_id);
        $this->assertSame(1, SocialAccount::query()->where('external_id', 'ext-shared-9')->count());

        $post = Post::factory()->forSocialAccount($social)->create([
            'type' => PostType::Reel,
            'external_id' => 'shared-reel-1',
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->assertSame(1, Post::query()->where('external_id', 'shared-reel-1')->count());
        $this->assertTrue(Post::query()->forUser($userA)->whereKey($post->id)->exists());
        $this->assertTrue(Post::query()->forUser($userB)->whereKey($post->id)->exists());
    }

    public function test_readding_tracked_handle_attaches_existing_global_posts(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'reattach_me',
            'external_id' => 'ext-reattach',
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $socialId = (int) $account->social_account_id;

        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'external_id' => 'reattach-reel',
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $account->delete();

        $readded = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'reattach_me',
            'external_id' => 'ext-reattach',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->assertSame($socialId, (int) $readded->social_account_id);
        $this->assertTrue(Post::query()->forUser($user)->whereKey($post->id)->exists());

        $this->actingAs($user)
            ->get(route('explore.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('posts.data', 1)
                    ->where('posts.data.0.id', $post->id)
                )
            );
    }
}
