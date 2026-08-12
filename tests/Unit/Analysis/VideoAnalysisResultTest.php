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

    public function test_from_model_payload_floors_short_hook_window(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 1.2],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'topics' => ['social proof'],
            'cta' => 'Apply today',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'sfx' => [],
            'is_original_audio' => true,
        ], 'qwen3.7-flash');

        $this->assertSame(3.0, $result->hookWindowEndSeconds);

        $customFloor = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 1.2],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'cta' => 'Apply today',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'sfx' => [],
        ], 'qwen3.7-flash', 4.0);

        $this->assertSame(4.0, $customFloor->hookWindowEndSeconds);
    }

    public function test_from_model_payload_extracts_transcript_string(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'topics' => ['social proof'],
            'cta' => 'Apply today',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'transcript' => "  I closed on a $12k grant this month.\nHere is how.  ",
            'sfx' => [],
        ], 'qwen3.7-flash');

        $this->assertSame(
            "I closed on a \$12k grant this month.\nHere is how.",
            $result->transcript,
        );
    }

    public function test_from_model_payload_normalizes_timestamped_transcript_array(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'cta' => 'Apply today',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'transcript' => [
                ['at_sec' => 0.4, 'text' => 'Look what we got.'],
                ['start' => 62, 'text' => 'Full breakdown in bio.'],
                'Wrap it up.',
            ],
            'sfx' => [],
        ], 'qwen3.7-flash');

        $this->assertSame(
            "[00:00] Look what we got.\n[01:02] Full breakdown in bio.\nWrap it up.",
            $result->transcript,
        );
    }

    public function test_from_model_payload_defaults_transcript_to_empty_string(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'cta' => 'Apply today',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'sfx' => [],
        ], 'qwen3.7-flash');

        $this->assertSame('', $result->transcript);
    }

    public function test_from_model_payload_tracks_output_truncated_flag(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'cta' => 'Apply today',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'transcript' => 'Still talking when the model ran out of tokens.',
            'sfx' => [],
        ], 'qwen3.7-flash', outputTruncated: true);

        $this->assertTrue($result->outputTruncated);
    }

    public function test_from_model_payload_floors_empty_cta(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-first before CTA',
            'hook' => 'Cold open on receipt',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => 'Tight crop on numbers then cut to face.',
            'idea' => 'Status proof via specific dollar amount',
            'topics' => ['social proof'],
            'cta' => '',
            'how_to_copy' => 'Lead with a concrete number, then your brand offer.',
            'sfx' => [],
            'is_original_audio' => true,
        ], 'qwen3.7-flash');

        $this->assertSame('No explicit CTA', $result->cta);
    }
}
