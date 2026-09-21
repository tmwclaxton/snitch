<?php

namespace Tests\Unit\Support;

use App\Models\Post;
use App\Models\PostAnalysis;
use App\Support\UsableAnalysisCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsableAnalysisCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_craft_copy_stays_visible(): void
    {
        $this->assertSame(
            'Curiosity then proof: show the messy brief.',
            UsableAnalysisCopy::displayValue('Curiosity then proof: show the messy brief.'),
        );
        $this->assertFalse(UsableAnalysisCopy::looksLikePlaceholder('Agency process as entertainment'));
    }

    public function test_faker_latin_is_not_usable(): void
    {
        $this->assertNull(UsableAnalysisCopy::displayValue(
            'Quia voluptas ut voluptatem a dolorum nulla impedit.',
        ));
        $this->assertTrue(UsableAnalysisCopy::looksLikePlaceholder('Illo fugit aut maiores.'));
        $this->assertTrue(UsableAnalysisCopy::looksLikePlaceholder('eligendi omnis et'));
    }

    public function test_apply_to_analysis_drops_placeholder_fields_and_guessed_music(): void
    {
        $analysis = PostAnalysis::factory()->for(Post::factory())->create([
            'idea' => 'Illo fugit aut maiores.',
            'visual_summary' => 'Quia voluptas ut voluptatem a dolorum nulla impedit.',
            'music' => [
                'title' => 'eligendi omnis et',
                'artist' => 'Evalyn Rempel',
            ],
        ]);

        UsableAnalysisCopy::applyToAnalysis($analysis);

        $this->assertNull($analysis->idea);
        $this->assertNull($analysis->visual_summary);
        $this->assertNull($analysis->music);
        $this->assertSame('Agency process as entertainment', $analysis->concept);
    }
}
