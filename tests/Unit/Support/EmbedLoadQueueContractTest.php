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
    public function feed_contact_cell_keeps_default_lazy_embeds(): void
    {
        $source = file_get_contents(base_path('resources/js/components/FeedContactCell.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('<PlatformEmbed', $source);
        $this->assertStringNotContainsString(':lazy="false"', $source);
        $this->assertStringNotContainsString('lazy="false"', $source);
    }

    #[Test]
    public function feed_contact_cell_uses_framed_proof_sheet_layout(): void
    {
        $source = file_get_contents(base_path('resources/js/components/FeedContactCell.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('snitch-contact-cell-header', $source);
        $this->assertStringContainsString('snitch-contact-cell-window', $source);
        $this->assertStringContainsString('snitch-contact-cell-body', $source);
        $this->assertStringContainsString('<ul', $source);
        $this->assertStringContainsString('snitch-glance-metrics', $source);
        $this->assertStringContainsString('snitch-glance-metric-value', $source);
        $this->assertStringContainsString('snitch-glance-tags', $source);
        $this->assertStringNotContainsString('snitch-contact-cell-meta', $source);
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
        $this->assertStringContainsString('text-overflow: ellipsis', $css);
        $this->assertStringContainsString('max-w-full min-w-0 overflow-hidden', $chipVue);
        $this->assertStringContainsString('min-w-0 truncate', $chipVue);
        $this->assertStringNotContainsString('flex-1 truncate', $chipVue);
    }

    #[Test]
    public function platform_embed_defaults_to_lazy_and_uses_queue(): void
    {
        $source = file_get_contents(base_path('resources/js/components/PlatformEmbed.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('lazy: true', $source);
        $this->assertStringContainsString('acquireEmbedSlot', $source);
        $this->assertStringContainsString('IntersectionObserver', $source);
    }
}
