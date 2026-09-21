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
        $this->assertStringNotContainsString('<iframe', $source);
        $this->assertStringNotContainsString('acquireEmbedSlot', $source);
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
        $this->assertStringContainsString('px-2 py-6 sm:px-3 sm:py-8', $dashboard);
        $this->assertStringContainsString('snitch-app-canvas', $dashboard);
        $this->assertStringContainsString('snitch-contact-sheet-proof', $dashboard);
        $this->assertStringContainsString('snitch-contact-sheet-proof-fill', $dashboard);
        $this->assertStringNotContainsString('xl:grid-cols-5', $dashboard);
        $this->assertStringNotContainsString('max-w-6xl', $dashboard);
        $this->assertStringNotContainsString('px-5 py-6 sm:px-8 sm:py-8', $dashboard);

        $css = file_get_contents(base_path('resources/css/app.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('.snitch-app-canvas', $css);
        $this->assertStringContainsString('max-width: none', $css);

        $feed = file_get_contents(base_path('resources/js/pages/feed/Index.vue'));
        $this->assertIsString($feed);
        $this->assertStringContainsString('snitch-contact-sheet-proof', $feed);
        $this->assertStringNotContainsString('xl:grid-cols-5', $feed);

        $explore = file_get_contents(base_path('resources/js/pages/explore/Index.vue'));
        $this->assertIsString($explore);
        $this->assertStringContainsString('snitch-contact-sheet-proof-fill', $explore);

        $show = file_get_contents(base_path('resources/js/pages/feed/Show.vue'));
        $this->assertIsString($show);
        $this->assertStringContainsString('minmax(0,12rem)', $show);
        $this->assertStringNotContainsString('minmax(0,18rem)', $show);
        $this->assertStringNotContainsString('!aspect-auto', $show);
        $this->assertStringContainsString('compact', $show);
    }
}
