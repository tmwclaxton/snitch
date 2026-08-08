<?php

namespace Tests\Unit\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;
use PHPUnit\Framework\TestCase;

class VideoAnalysisResultTest extends TestCase
{
    public function test_from_model_payload_maps_concept_and_topics(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'topics' => ['social proof', 'funding meme'],
            'cta' => 'Apply today',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'sfx' => [],
            'is_original_audio' => true,
        ], 'qwen3.7-flash');

        $this->assertSame('Proof-first before CTA', $result->concept);
        $this->assertSame(['social proof', 'funding meme'], $result->topics);
        $this->assertSame('Lead with a concrete number, then your brand offer.', $result->howToCopy);
        $this->assertSame([], $result->hookTypeSlugs);
        $this->assertSame([], $result->customTags);
    }

    public function test_from_model_payload_maps_taxonomy_slugs_and_custom_tags(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'topics' => ['social proof'],
            'hook_type_slugs' => ['Shock Stat', 'unknown-slug!!'],
            'topic_slugs' => ['grant_writing'],
            'visual_craft_slugs' => ['talking_head'],
            'custom_tags' => ['foundation-report-drop'],
            'cta' => 'Apply today',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'sfx' => [],
            'is_original_audio' => true,
        ], 'qwen3.7-flash');

        $this->assertSame(['shockstat', 'unknown-slug'], $result->hookTypeSlugs);
        $this->assertSame(['grant_writing'], $result->topicSlugs);
        $this->assertSame(['talking_head'], $result->visualCraftSlugs);
        $this->assertSame(['foundation-report-drop'], $result->customTags);
        $this->assertTrue($result->hasTaxonomySignal());
    }
}
