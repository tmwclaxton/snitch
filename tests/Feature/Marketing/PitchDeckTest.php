<?php

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PitchDeckTest extends TestCase
{
    use RefreshDatabase;

    public function test_pitch_deck_is_a_standalone_html_page(): void
    {
        $this->get(route('pitch'))
            ->assertOk()
            ->assertSee('content="noindex, nofollow"', false)
            ->assertSee('Know what works.', false)
            ->assertSee('Outperform your market', false)
            ->assertSee('Fund the proof.', false)
            ->assertSee('£10', false)
            ->assertSee('/images/marketing/hero/mascot-character.png', false)
            ->assertSee('/images/marketing/hero/mascot-binos.png', false)
            ->assertSee('/images/brand/mascot-mark.png', false)
            ->assertSee('Socialinsider', false)
            ->assertDontSee('TAM:', false)
            ->assertSee('container-type: size', false)
            ->assertSee('class="deck"', false)
            ->assertSee('class="chrome"', false);
    }

    public function test_self_contained_pitch_html_embeds_assets(): void
    {
        $path = base_path('snitch-pitch.html');

        $this->assertFileExists($path);

        $html = file_get_contents($path);

        $this->assertNotFalse($html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('data:font/woff2;base64,', $html);
        $this->assertStringContainsString('Fund the proof.', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('/images/marketing', $html);
        $this->assertStringNotContainsString('fonts.bunny.net', $html);
    }

    public function test_pitch_deck_stays_out_of_the_sitemap(): void
    {
        $this->get(route('sitemap'))
            ->assertOk()
            ->assertDontSee(route('pitch', absolute: true), false);
    }
}
