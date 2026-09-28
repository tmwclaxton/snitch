<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmbedLoadQueueContractTest extends TestCase
{
    #[Test]
    public function embed_load_queue_caps_concurrent_iframe_starts(): void
    {
        $source = file_get_contents(base_path('resources/js/lib/embedLoadQueue.ts'));

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression('/MAX_CONCURRENT\s*=\s*2\b/', $source);
        $this->assertStringContainsString('acquireEmbedSlot', $source);
        $this->assertStringContainsString('releaseEmbedSlot', $source);
    }

    #[Test]
    public function feed_contact_cell_shows_covers_not_iframes(): void
    {
        $source = file_get_contents(base_path('resources/js/components/FeedContactCell.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('<PlatformEmbed', $source);
        $this->assertStringContainsString(':cover-url="post.cover_url"', $source);
        $this->assertStringNotContainsString('<iframe', $source);
    }

    #[Test]
    public function feed_contact_cell_uses_framed_proof_sheet_layout(): void
    {
        $source = file_get_contents(base_path('resources/js/components/FeedContactCell.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('snitch-contact-cell-header', $source);
        $this->assertStringContainsString('snitch-contact-cell-window', $source);
        $this->assertStringContainsString('snitch-contact-cell-body', $source);
        $this->assertStringContainsString('snitch-contact-cell-hit', $source);
        $this->assertStringContainsString('feedShow.url(post.id)', $source);
        $this->assertStringContainsString('snitch-glance-account-link', $source);
        $this->assertStringNotContainsString('snitch-contact-cell-body-link', $source);
        $this->assertStringContainsString('<ul', $source);
        $this->assertStringContainsString('snitch-glance-metrics', $source);
        $this->assertStringContainsString('snitch-glance-metric-value', $source);
        $this->assertStringContainsString('snitch-glance-tags', $source);
        $this->assertStringNotContainsString('snitch-contact-cell-meta', $source);
    }

    #[Test]
    public function glance_and_topic_tags_share_one_paper_treatment(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));

        $this->assertIsString($css);
        $this->assertStringNotContainsString('.snitch-glance-tag:nth-child(2)', $css);
        $this->assertStringNotContainsString('.snitch-glance-tag:nth-child(3)', $css);
        $this->assertStringNotContainsString('.snitch-topic-chip:nth-child(even)', $css);
        $this->assertStringNotContainsString(
            '.snitch-glance-metric:first-child .snitch-glance-metric-value',
            $css,
        );
        $this->assertStringContainsString(
            'box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--snitch-ink) 16%, transparent)',
            $css,
        );
        $this->assertStringContainsString('flex-wrap: nowrap', $css);
        $this->assertStringContainsString('container-type: inline-size', $css);
    }

    #[Test]
    public function contact_cell_tag_styles_constrain_long_glance_chips(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));
        $chipVue = file_get_contents(base_path('resources/js/components/AnalysisTermChip.vue'));

        $this->assertIsString($css);
        $this->assertIsString($chipVue);
        $this->assertStringContainsString('.snitch-contact-cell .snitch-glance-tag', $css);
        $this->assertStringNotContainsString(
            '.snitch-contact-cell .snitch-glance-tag:first-child',
            $css,
        );
        $this->assertStringContainsString('width: max-content', $css);
        $this->assertStringContainsString('text-overflow: ellipsis', $css);
        $this->assertStringContainsString('w-max max-w-full min-w-0 overflow-hidden', $chipVue);
        $this->assertStringNotContainsString('flex-1', $chipVue);
        $this->assertStringContainsString('min-w-0 truncate', $chipVue);
        $this->assertStringNotContainsString('flex-1 truncate', $chipVue);
    }

    #[Test]
    public function platform_embed_renders_covers_instead_of_iframes(): void
    {
        $source = file_get_contents(base_path('resources/js/components/PlatformEmbed.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('coverUrl', $source);
        $this->assertStringContainsString('usableCoverUrl', $source);
        $this->assertStringContainsString('v-if="interactiveSrc"', $source);
        $this->assertStringContainsString("'/embed/captioned/'", $source);
        $this->assertStringContainsString("'/embed/'", $source);
        $this->assertStringContainsString('fallback="paper"', $source);
        $this->assertStringNotContainsString('Preview unavailable', $source);
        $this->assertStringNotContainsString('media link expired', $source);
        $this->assertStringNotContainsString('acquireEmbedSlot', $source);
        $this->assertStringContainsString('props.compact', $source);
    }

    #[Test]
    public function feed_contact_cell_does_not_pass_cdn_media_url_to_compact_embeds(): void
    {
        $source = file_get_contents(base_path('resources/js/components/FeedContactCell.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString(':cover-url="post.cover_url"', $source);
        $this->assertStringNotContainsString(':media-url="post.media_url"', $source);
    }

    #[Test]
    public function dashboard_winner_media_fills_the_polaroid_frame(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('.snitch-dashboard-winner-media .snitch-polaroid-frame .snitch-platform-embed', $css);
        $this->assertStringContainsString('aspect-ratio: auto', $css);
        $this->assertStringContainsString('width: 6.5rem', $css);
        $this->assertStringContainsString('width: 7.75rem', $css);
        $this->assertStringContainsString('.snitch-contact-cell-hit', $css);
        $this->assertStringContainsString('.snitch-contact-sheet-proof', $css);
        $this->assertStringContainsString(
            'repeat(auto-fill, minmax(8.5rem, 10.75rem))',
            $css,
        );
        $this->assertStringContainsString('.snitch-contact-sheet-proof-fill', $css);
        $this->assertStringContainsString(
            'repeat(auto-fit, minmax(12rem, 1fr))',
            $css,
        );
        $this->assertStringNotContainsString('min-height: 14rem', $css);

        $dashboard = file_get_contents(base_path('resources/js/pages/Dashboard.vue'));
        $this->assertIsString($dashboard);
        $this->assertStringContainsString('px-2 py-2 sm:px-3', $dashboard);
        $this->assertStringContainsString('WinnerCard', $dashboard);
        $this->assertStringNotContainsString('id="recent_posts"', $dashboard);
        $this->assertStringNotContainsString('Latest posts', $dashboard);
        $this->assertStringContainsString('id="format_mix"', $dashboard);
        $this->assertStringContainsString('id="activity"', $dashboard);
        $this->assertStringContainsString('id="caption_intel"', $dashboard);
        $this->assertStringContainsString('snitch-heatmap--dash', $dashboard);
        $this->assertStringContainsString('snitch-dash-chip-stack', $dashboard);
        $this->assertStringContainsString('items-stretch gap-2 lg:grid-cols-2', $dashboard);
        $this->assertStringContainsString('showFollowerNote', $dashboard);
        $this->assertStringContainsString('first snapshot only', $dashboard);
        $this->assertStringContainsString('flex-wrap items-center gap-x-1.5', $dashboard);
        $this->assertStringNotContainsString('max-w-[6.5rem]', $dashboard);
        $this->assertStringContainsString('anchor="winners"', $dashboard);
        $this->assertStringContainsString('anchor="insights"', $dashboard);
        $this->assertStringContainsString('followDashboardLink', file_get_contents(base_path('resources/js/components/dashboard/InsightList.vue')) ?: '');
        $winnerCard = file_get_contents(base_path('resources/js/components/dashboard/WinnerCard.vue'));
        $this->assertIsString($winnerCard);
        $this->assertStringContainsString('flex h-full flex-col', $winnerCard);
        $this->assertStringContainsString('their usual', $winnerCard);
        $this->assertStringContainsString('Open on Instagram', $winnerCard);
        $this->assertStringContainsString('early estimate', $winnerCard);
        $this->assertStringContainsString('break-words', $winnerCard);
        $this->assertStringNotContainsString('line-clamp-', $winnerCard);
        $captions = file_get_contents(base_path('resources/js/components/dashboard/CaptionPanels.vue'));
        $this->assertIsString($captions);
        $this->assertStringContainsString('Caption length vs results', $captions);
        $this->assertStringContainsString('Call to action', $captions);
        $this->assertStringContainsString('Hashtag count', $captions);
        $this->assertStringContainsString('too few posts', $captions);
        $this->assertStringContainsString('Results = a post\'s engagement vs that account\'s usual', $captions);
        $this->assertStringContainsString('multiplierBarWidth', $captions);
        $this->assertStringContainsString('auto-fit,minmax(9.5rem,1fr)', $captions);
        $this->assertStringContainsString('Winning hooks', $captions);
        $this->assertStringContainsString('line-clamp-3', $captions);
        $this->assertStringContainsString('break-all text-slate-500', $captions);
        $this->assertStringNotContainsString('Length vs PI', $captions);
        $this->assertStringNotContainsString('>CTAs<', $captions);
        $compare = file_get_contents(base_path('resources/js/components/dashboard/CompareTable.vue'));
        $this->assertIsString($compare);
        $this->assertStringContainsString('postTypeShortLabel', $compare);
        $this->assertStringContainsString('break-words font-medium', $compare);
        $themes = file_get_contents(base_path('resources/js/components/dashboard/ThemeMatrix.vue'));
        $this->assertIsString($themes);
        $this->assertStringContainsString('accountHeading', $themes);
        $this->assertStringNotContainsString('slice(0, 12)', $themes);

        $css = file_get_contents(base_path('resources/css/app.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('.snitch-app-canvas', $css);
        $this->assertStringContainsString('max-width: none', $css);

        $feed = file_get_contents(base_path('resources/js/pages/feed/Index.vue'));
        $this->assertIsString($feed);
        $this->assertStringContainsString('snitch-contact-sheet-proof', $feed);
        $this->assertStringContainsString('snitch-contact-sheet-proof-fill', $feed);
        $this->assertStringNotContainsString('xl:grid-cols-5', $feed);

        $explore = file_get_contents(base_path('resources/js/pages/explore/Index.vue'));
        $this->assertIsString($explore);
        $this->assertStringContainsString('snitch-contact-sheet-proof-fill', $explore);

        $show = file_get_contents(base_path('resources/js/pages/feed/Show.vue'));
        $this->assertIsString($show);
        $this->assertStringContainsString('snitch-post-fit', $show);
        $this->assertStringContainsString('snitch-post-player', $show);
        $this->assertStringContainsString('interactive', $show);
        $this->assertStringNotContainsString('minmax(0,12rem)', $show);
        $this->assertStringNotContainsString('!aspect-auto', $show);
        $this->assertStringNotContainsString('compact', $show);

        $css = file_get_contents(base_path('resources/css/app.css'));
        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-post-player\s+\[data-embed-provider=[\'"]instagram[\'"]\]\s+\.snitch-platform-embed-frame\s*\{[^}]*aspect-ratio:\s*4\s*\/\s*5/s',
            $css,
        );
    }

    #[Test]
    public function post_page_puts_the_caption_under_the_player(): void
    {
        $show = file_get_contents(base_path('resources/js/pages/feed/Show.vue'));
        $this->assertIsString($show);

        $player = strpos($show, 'snitch-post-player');
        $caption = strpos($show, 'snitch-post-caption');
        $notes = strpos($show, 'snitch-post-notes');

        $this->assertNotFalse($player);
        $this->assertNotFalse($caption);
        $this->assertNotFalse($notes);
        $this->assertLessThan($caption, $player);
        $this->assertLessThan($notes, $caption);
    }

    #[Test]
    public function post_page_does_not_scroll_caption_or_notes_in_a_locked_viewport(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));
        $this->assertIsString($css);

        $this->assertDoesNotMatchRegularExpression(
            '/\.snitch-post-fit[^{]*\{[^}]*height:\s*calc\(100svh\s*-\s*4rem\)/s',
            $css,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.snitch-post-fit\s+\.snitch-post-caption\s*\{[^}]*overflow:\s*auto/s',
            $css,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.snitch-post-notes\s*\{[^}]*overflow:\s*auto/s',
            $css,
        );
    }
}
