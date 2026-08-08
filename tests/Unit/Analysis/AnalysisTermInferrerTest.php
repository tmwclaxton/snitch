<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\AnalysisTermInferrer;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisTermInferrerTest extends TestCase
{
    use RefreshDatabase;

    public function test_infers_myth_bust_from_freeform_myth_busting_topics(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $slugs = app(AnalysisTermInferrer::class)->inferSlugs([
            'hook' => "Visual text overlay: 'LIE: Just get your 501(c)(3), and you can get grants.' stamped with 'FALSE'.",
            'concept' => "A split-screen format using a high-contrast 'myth-busting' graphic on one side.",
            'idea' => 'Gamified lead magnet gate after the myth callout.',
            'visual_summary' => 'Split screen with bold FALSE stamp over the claim.',
            'topics' => ['split-screen marketing', 'myth-busting hook', 'simulated q&a'],
            'custom_tags' => [],
        ]);

        $this->assertContains('myth_bust', $slugs['hook_type']);
    }

    public function test_does_not_match_tiny_aliases(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        $slugs = app(AnalysisTermInferrer::class)->inferSlugs([
            'hook' => 'Quick tip for founders',
            'concept' => 'A short tip about pacing',
            'idea' => 'Useful pacing tip',
            'visual_summary' => 'Talking to camera in an office',
            'topics' => ['tips'],
            'custom_tags' => [],
        ]);

        $this->assertNotContains('cta_first', $slugs['hook_type']);
    }
}
