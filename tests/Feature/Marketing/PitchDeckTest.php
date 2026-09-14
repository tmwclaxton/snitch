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
            ->assertSee('class="chrome"', false)
            ->assertDontSee('grid-template-columns: 6fr 3fr 1fr', false)
            ->assertSee('The winning posts are already public', false);
    }

    public function test_pitch_deck_stays_out_of_the_sitemap(): void
    {
        $this->get(route('sitemap'))
            ->assertOk()
            ->assertDontSee(route('pitch', absolute: true), false);
    }
}
