<?php

namespace Tests\Unit\Analysis;

use App\Enums\AnalysisTermDimension;
use App\Services\Analysis\AnalysisTermCatalogue;
use Tests\TestCase;

class AnalysisTermCatalogueTest extends TestCase
{
    public function test_catalogue_has_about_250_unique_dimension_slugs(): void
    {
        $definitions = (new AnalysisTermCatalogue)->definitions();

        $this->assertCount(250, $definitions);

        $keys = [];
        foreach ($definitions as $row) {
            $this->assertContains($row['dimension'], AnalysisTermDimension::values());
            $this->assertNotSame('', $row['slug']);
            $this->assertNotSame('', $row['label']);
            $this->assertNotSame('', $row['section']);
            $keys[] = $row['dimension'].':'.$row['slug'];
        }

        $this->assertCount(250, array_unique($keys));
        $this->assertGreaterThan(5, count(array_unique(array_column($definitions, 'section'))));
    }

    public function test_prompt_block_lists_each_dimension(): void
    {
        $block = (new AnalysisTermCatalogue)->promptBlock();

        $this->assertStringContainsString('hook_type:', $block);
        $this->assertStringContainsString('topic:', $block);
        $this->assertStringContainsString('visual_craft:', $block);
        $this->assertStringContainsString('pattern_interrupt', $block);
    }
}
