<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\AnalysisTerm;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExploreTest extends TestCase
{
    use RefreshDatabase;

    public function test_explore_uses_sectioned_multi_select_pickers(): void
    {
        $indexVue = file_get_contents(resource_path('js/pages/explore/Index.vue'));
        $pickerVue = file_get_contents(resource_path('js/components/PaperTermPicker.vue'));
        $chipVue = file_get_contents(resource_path('js/components/AnalysisTermChip.vue'));

        $this->assertIsString($indexVue);
        $this->assertIsString($pickerVue);
        $this->assertIsString($chipVue);
        $this->assertStringContainsString('PaperTermPicker', $indexVue);
        $this->assertStringContainsString('Open a catalogue picker', $indexVue);
        $this->assertStringContainsString('Browse every hook pattern by section', $indexVue);
        $this->assertStringContainsString('dimension="hook_type"', $indexVue);
        $this->assertStringContainsString('aria-pressed', $pickerVue);
        $this->assertStringContainsString('Clear selection', $pickerVue);
        $this->assertStringContainsString('Apply', $pickerVue);
        $this->assertStringContainsString('AnalysisTermChip', $pickerVue);
        $this->assertStringContainsString(':count="term.count"', $pickerVue);
        $this->assertStringContainsString('· {{ count }}', $chipVue);
    }

    public function test_explore_lists_completed_analyses_across_corpus(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'external_id' => 'own-reel-1',
        ]);
        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $term = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'pattern_interrupt')
            ->firstOrFail();
        $analysis->terms()->attach($term->id);

        $otherAccount = TrackedAccount::factory()->for($other)->create();
        $otherPost = Post::factory()->forAccount($otherAccount)->create([
            'type' => PostType::Reel,
            'external_id' => 'other-reel-1',
        ]);
        PostAnalysis::factory()->for($otherPost)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['platform' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->missing('posts')
                ->missing('terms')
                ->where('filters.hook_types', [])
                ->where('filters.custom_tag', null)
                ->missing('accounts')
                ->missing('filters.account')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('posts.data', 2)
                    ->where('posts.data', function ($posts) use ($post, $otherPost, $term): bool {
                        $ids = collect($posts)->pluck('id')->all();
                        $own = collect($posts)->firstWhere('id', $post->id);

                        return in_array($post->id, $ids, true)
                            && in_array($otherPost->id, $ids, true)
                            && is_array($own)
                            && ($own['analysis']['term_labels'][0]['slug'] ?? null) === $term->slug
                            && ($own['analysis']['term_labels'][0]['section'] ?? null) === 'Claims & takes';
                    })
                )
                ->loadDeferredProps('terms', fn (Assert $page) => $page
                    ->has('terms.hook_type')
                    ->where('terms.hook_type.0.section', fn ($section) => is_string($section) && $section !== '')
                    ->where('terms.hook_type', function ($terms) use ($term): bool {
                        $match = collect($terms)->firstWhere('slug', $term->slug);

                        return is_array($match)
                            && ($match['count'] ?? null) === 1;
                    })
                    ->has('terms.topic')
                    ->has('terms.visual_craft')
                )
            );
    }

    public function test_explore_term_counts_include_corpus(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $term = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'myth_bust')
            ->firstOrFail();

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'external_id' => 'own-myth',
        ]);
        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $analysis->terms()->attach($term->id);

        $otherAccount = TrackedAccount::factory()->for($other)->create();
        $otherPost = Post::factory()->forAccount($otherAccount)->create([
            'type' => PostType::Reel,
            'external_id' => 'other-myth',
        ]);
        $otherAnalysis = PostAnalysis::factory()->for($otherPost)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $otherAnalysis->terms()->attach($term->id);

        $this->actingAs($user)
            ->get(route('explore.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->missing('terms')
                ->loadDeferredProps('terms', fn (Assert $page) => $page
                    ->where('terms.hook_type', function ($terms) use ($term): bool {
                        $match = collect($terms)->firstWhere('slug', $term->slug);

                        return is_array($match)
                            && ($match['count'] ?? null) === 2;
                    })
                )
            );
    }

    public function test_explore_accepts_singular_topic_query_param(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $term = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::Topic)
            ->where('slug', 'fundraising')
            ->firstOrFail();
        $analysis->terms()->attach($term->id);

        $other = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($other)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['topics' => 'fundraising']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->where('filters.topics', ['fundraising'])
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('posts.data', 1)
                    ->where('posts.data.0.id', $post->id)
                )
            );
    }

    public function test_explore_filters_by_multiple_hook_type_slugs(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $matching = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $matchingAnalysis = PostAnalysis::factory()->for($matching)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Pattern break open',
        ]);
        $hookTerm = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'pattern_interrupt')
            ->firstOrFail();
        $matchingAnalysis->terms()->attach($hookTerm->id);

        $alsoMatching = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $alsoAnalysis = PostAnalysis::factory()->for($alsoMatching)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $boldTerm = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'bold_claim')
            ->firstOrFail();
        $alsoAnalysis->terms()->attach($boldTerm->id);

        $other = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $otherAnalysis = PostAnalysis::factory()->for($other)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        $otherTerm = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'question_hook')
            ->firstOrFail();
        $otherAnalysis->terms()->attach($otherTerm->id);

        $this->actingAs($user)
            ->get(route('explore.index', [
                'hook_types' => ['pattern_interrupt', 'bold_claim'],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->where('filters.hook_types', ['pattern_interrupt', 'bold_claim'])
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('posts.data', 2)
                )
            );
    }

    public function test_explore_search_matches_custom_tags(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditFromTopUp($user, 1000, 'topup:explore-search-tags');
        $account = TrackedAccount::factory()->for($user)->create();

        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->create([
            'post_id' => $post->id,
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['foundation-report-drop'],
            'hook' => 'Cold open on PDF',
        ]);

        $other = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->create([
            'post_id' => $other->id,
            'status' => AnalysisStatus::Completed,
            'custom_tags' => ['unrelated'],
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['q' => 'foundation-report']))
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

    public function test_explore_search_matches_topics(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditFromTopUp($user, 1000, 'topup:explore-search-topics');
        $account = TrackedAccount::factory()->for($user)->create();

        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'topics' => ['myth-busting hook', 'lead magnet gating'],
        ]);

        $other = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($other)->create([
            'status' => AnalysisStatus::Completed,
            'topics' => ['unrelated craft'],
        ]);

        $this->actingAs($user)
            ->get(route('explore.index', ['q' => 'myth-busting']))
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

    public function test_explore_defaults_to_all_platforms_when_user_tracks_only_instagram(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $instagram = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'onlyig',
        ]);
        $other = User::factory()->create();
        $tiktok = TrackedAccount::factory()->for($other)->create([
            'platform' => Platform::TikTok,
            'handle' => 'othertt',
        ]);

        $igPost = Post::factory()->forAccount($instagram)->create([
            'type' => PostType::Reel,
            'platform' => Platform::Instagram,
            'external_id' => 'ig-explore-default-1',
        ]);
        $ttPost = Post::factory()->forAccount($tiktok)->create([
            'type' => PostType::Reel,
            'platform' => Platform::TikTok,
            'external_id' => 'tt-explore-default-1',
        ]);

        PostAnalysis::factory()->for($igPost)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        PostAnalysis::factory()->for($ttPost)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->actingAs($user)
            ->get(route('explore.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->where('filters.platform', null)
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('posts.data', 2)
                    ->where('posts.data', function ($posts) use ($igPost, $ttPost): bool {
                        $ids = collect($posts)->pluck('id')->all();

                        return in_array($igPost->id, $ids, true)
                            && in_array($ttPost->id, $ids, true);
                    })
                )
            );
    }

    public function test_explore_sheet_uses_format_labels_and_clamped_copy(): void
    {
        $indexVue = file_get_contents(resource_path('js/pages/explore/Index.vue'));
        $cellVue = file_get_contents(resource_path('js/components/FeedContactCell.vue'));
        $embedVue = file_get_contents(resource_path('js/components/PlatformEmbed.vue'));
        $controller = file_get_contents(app_path('Http/Controllers/ExploreController.php'));

        $this->assertIsString($indexVue);
        $this->assertIsString($cellVue);
        $this->assertIsString($embedVue);
        $this->assertIsString($controller);
        $this->assertStringContainsString("props.filters.platform ?? 'all'", $indexVue);
        $this->assertStringContainsString('{{ postTypeLabel(post.type) }}', $cellVue);
        $this->assertStringNotContainsString('productPlatformLabel(platform) }} ·', $cellVue);
        $this->assertStringContainsString('showHookLine', $cellVue);
        $this->assertStringContainsString('Show more', $cellVue);
        $this->assertStringNotContainsString('snitch-platform-embed-play', $embedVue);
        $this->assertStringNotContainsString('>Play<', $embedVue);
        $this->assertStringNotContainsString('default to the sole tracked platform', $controller);
    }

    public function test_explore_payload_includes_platform_for_each_item(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $instagram = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'igexplorer',
        ]);
        $tiktok = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::TikTok,
            'handle' => 'ttexplorer',
        ]);

        $igPost = Post::factory()->forAccount($instagram)->create([
            'type' => PostType::Reel,
            'platform' => Platform::Instagram,
            'external_id' => 'ig-explore-platform-1',
        ]);
        $ttPost = Post::factory()->forAccount($tiktok)->create([
            'type' => PostType::Reel,
            'platform' => Platform::TikTok,
            'external_id' => 'tt-explore-platform-1',
        ]);

        PostAnalysis::factory()->for($igPost)->create([
            'status' => AnalysisStatus::Completed,
        ]);
        PostAnalysis::factory()->for($ttPost)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $this->actingAs($user)
            ->get(route('explore.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('explore/Index')
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('posts.data', 2)
                    ->where('posts.data', function ($posts) use ($igPost, $ttPost): bool {
                        $rows = collect($posts);
                        $ig = $rows->firstWhere('id', $igPost->id);
                        $tt = $rows->firstWhere('id', $ttPost->id);

                        if ($ig === null || $tt === null) {
                            return false;
                        }

                        $igPlatform = data_get($ig, 'platform');
                        $ttPlatform = data_get($tt, 'platform');
                        $ttTracked = data_get($tt, 'tracked_account.platform');

                        return $igPlatform === 'instagram'
                            && $ttPlatform === 'tiktok'
                            && $ttTracked === 'tiktok';
                    })
                )
            );
    }
}
