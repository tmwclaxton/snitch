<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
use App\Enums\MediaAvailability;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\AnalysisTerm;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_lists_only_owner_posts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($post)->create();

        $otherAccount = TrackedAccount::factory()->for($other)->create();
        Post::factory()->forAccount($otherAccount)->create();

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Index')
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('posts.data', 1)
                    ->where('posts.data.0.id', $post->id)
                )
            );
    }

    public function test_post_detail_is_authorized(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        BrandProfile::factory()->for($other)->create();

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create();

        $this->actingAs($user)
            ->get(route('feed.show', $post))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('feed/Show'));

        $this->actingAs($other)
            ->get(route('feed.show', $post))
            ->assertForbidden();
    }

    public function test_feed_includes_platform_embed_payload(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::TikTok,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::TikTok,
            'url' => 'https://www.tiktok.com/@demo/video/6718335390845095173',
        ]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Index')
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->where('posts.data.0.id', $post->id)
                    ->where('posts.data.0.embed.provider', 'tiktok')
                    ->where(
                        'posts.data.0.embed.src',
                        'https://www.tiktok.com/player/v1/6718335390845095173?music_info=0&description=0&autoplay=0',
                    )
                )
            );
    }

    public function test_feed_index_exposes_glance_fields(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'caption' => "GrantTalk Ep 30: Classroom grants\nMore body copy",
            'metrics' => [
                'views' => 12000,
                'likes' => 840,
                'comments' => 12,
                'shares' => 0,
            ],
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Cuts on the gasp',
            'concept' => 'Contrast reveal',
            'topics' => ['before after', 'receipts'],
        ]);
        WinnerInsight::factory()->forPost($post)->create([
            'score' => 91.2,
        ]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Index')
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->where('posts.data.0.id', $post->id)
                    ->where('posts.data.0.caption', "GrantTalk Ep 30: Classroom grants\nMore body copy")
                    ->where('posts.data.0.metrics.views', 12000)
                    ->where('posts.data.0.analysis.hook', 'Cuts on the gasp')
                    ->where('posts.data.0.analysis.concept', 'Contrast reveal')
                    ->where('posts.data.0.analysis.topics.0', 'before after')
                    ->where('posts.data.0.winner_insight.score', 91.2)
                    ->where('posts.data.0.tracked_account.id', $account->id)
                    ->where('posts.data.0.tracked_account.handle', $account->handle)
                )
            );
    }

    public function test_post_detail_exposes_concept_first_analysis(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'caption' => 'Long caption that should stay secondary',
        ]);
        PostAnalysis::factory()->for($post)->create([
            'concept' => 'Pattern interrupt then proof',
            'how_to_copy' => 'Open on the mess. Cut to the receipt. End on the ask.',
            'transcript' => "Look at this mess.\nHere is the receipt.\nHere is the ask.",
            'topics' => ['interrupt', 'proof'],
        ]);
        WinnerInsight::factory()->forPost($post)->create([
            'score' => 88.0,
            'why' => 'Strong remake candidate',
            'how_to_copy' => "**1. Hook with the void.**\nOpen on *speaker*.",
        ]);

        $this->actingAs($user)
            ->get(route('feed.show', $post))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Show')
                ->where('post.type', 'reel')
                ->where('post.caption', 'Long caption that should stay secondary')
                ->where('post.analysis.concept', 'Pattern interrupt then proof')
                ->where('post.analysis.how_to_copy', 'Open on the mess. Cut to the receipt. End on the ask.')
                ->where(
                    'post.analysis.how_to_copy_html',
                    "<ol>\n<li>Open on the mess.</li>\n<li>Cut to the receipt.</li>\n<li>End on the ask.</li>\n</ol>",
                )
                ->where(
                    'post.analysis.transcript',
                    "Look at this mess.\nHere is the receipt.\nHere is the ask.",
                )
                ->where('post.analysis.topics.1', 'proof')
                ->where('post.tracked_account.id', $account->id)
                ->where(
                    'post.winner_insight.how_to_copy_html',
                    "<p><strong>1. Hook with the void.</strong>\nOpen on <em>speaker</em>.</p>",
                )
            );
    }

    public function test_feed_hides_image_posts_and_exposes_failed_vs_unavailable(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $reel = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($reel)->create([
            'status' => AnalysisStatus::Failed,
            'error_message' => 'checklist failed',
            'hook' => null,
        ]);

        $unavailable = Post::factory()->forAccount($account)->create([
            'type' => PostType::Video,
            'media_availability' => MediaAvailability::Unavailable,
            'unavailable_reason' => 'Media expired',
        ]);
        PostAnalysis::factory()->for($unavailable)->create([
            'status' => AnalysisStatus::Unavailable,
            'error_message' => 'Media expired',
            'hook' => null,
        ]);

        Post::factory()->forAccount($account)->create([
            'type' => PostType::Image,
        ]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Index')
                ->where('types', ['reel', 'video'])
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('posts.data', 2)
                )
            );

        $this->actingAs($user)
            ->get(route('feed.show', $unavailable))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Show')
                ->where('post.media_availability', 'unavailable')
                ->where('post.analysis.status', 'unavailable')
            );
    }

    public function test_post_detail_includes_platform_embed_or_null_fallback(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
        ]);
        $withEmbed = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'url' => 'https://www.instagram.com/reel/CxYz123AbCd/',
        ]);
        $withoutEmbed = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'url' => 'https://example.com/not-instagram',
        ]);

        $this->actingAs($user)
            ->get(route('feed.show', $withEmbed))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Show')
                ->where('post.embed.provider', 'instagram')
                ->where(
                    'post.embed.src',
                    'https://www.instagram.com/reel/CxYz123AbCd/embed/captioned/',
                )
            );

        $this->actingAs($user)
            ->get(route('feed.show', $withoutEmbed))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Show')
                ->where('post.embed', null)
            );
    }

    public function test_post_detail_omits_transcript_when_analysis_has_none(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($post)->create([
            'transcript' => null,
        ]);

        $this->actingAs($user)
            ->get(route('feed.show', $post))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Show')
                ->where('post.analysis.transcript', null)
            );
    }

    public function test_feed_show_vue_wires_transcript_button_and_modal(): void
    {
        $showVue = file_get_contents(resource_path('js/pages/feed/Show.vue'));

        $this->assertIsString($showVue);
        $this->assertStringContainsString('Transcript', $showVue);
        $this->assertStringContainsString('post.analysis?.transcript', $showVue);
        $this->assertStringContainsString('TranscriptModal', $showVue);
    }

    public function test_feed_index_vue_omits_frame_and_exposure_count_meta(): void
    {
        $indexVue = file_get_contents(resource_path('js/pages/feed/Index.vue'));

        $this->assertIsString($indexVue);
        $this->assertStringContainsString('Proof sheet', $indexVue);
        $this->assertStringNotContainsString('frameCount', $indexVue);
        $this->assertStringNotContainsString('exposures', $indexVue);
        $this->assertStringNotContainsString("'Frame' : 'Frames'", $indexVue);
    }

    public function test_feed_show_marks_winners_with_compact_tag_not_sticker_section(): void
    {
        $showVue = file_get_contents(resource_path('js/pages/feed/Show.vue'));

        $this->assertIsString($showVue);
        $this->assertStringContainsString('post.winner_insight', $showVue);
        $this->assertStringContainsString('Trophy', $showVue);
        $this->assertStringContainsString('Winner', $showVue);
        $this->assertStringNotContainsString('Winner ·', $showVue);
        $this->assertStringNotContainsString('post.winner_insight.why', $showVue);
        $this->assertStringNotContainsString('post.winner_insight.how_to_copy', $showVue);
    }

    public function test_feed_term_chips_link_to_explore_with_preselected_filters(): void
    {
        $showVue = file_get_contents(resource_path('js/pages/feed/Show.vue'));
        $cellVue = file_get_contents(resource_path('js/components/FeedContactCell.vue'));
        $chipVue = file_get_contents(resource_path('js/components/AnalysisTermChip.vue'));
        $helper = file_get_contents(resource_path('js/lib/analysisTerms.ts'));

        $this->assertIsString($showVue);
        $this->assertIsString($cellVue);
        $this->assertIsString($chipVue);
        $this->assertIsString($helper);
        $this->assertStringContainsString('exploreHrefForTerm', $showVue);
        $this->assertStringContainsString('exploreHrefForTerm', $cellVue);
        $this->assertStringContainsString(':href="exploreHrefForTerm', $showVue);
        $this->assertStringContainsString('hook_types', $helper);
        $this->assertStringContainsString('visual_crafts', $helper);
        $this->assertStringContainsString('custom_tag', $helper);
        $this->assertStringContainsString('v-if="isLink"', $chipVue);
    }

    public function test_feed_includes_analysis_term_labels_with_sections(): void
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

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Index')
                ->missing('posts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->where('posts.data.0.analysis.term_labels.0.slug', 'fundraising')
                    ->where('posts.data.0.analysis.term_labels.0.section', 'Grants & nonprofit')
                )
            );

        $this->actingAs($user)
            ->get(route('feed.show', $post))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Show')
                ->where('post.analysis.term_labels.0.slug', 'fundraising')
                ->where('post.analysis.term_labels.0.dimension', 'topic')
            );
    }
}
