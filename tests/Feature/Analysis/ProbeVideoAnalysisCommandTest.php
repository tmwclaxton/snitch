<?php

namespace Tests\Feature\Analysis;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProbeVideoAnalysisCommandTest extends TestCase
{
    public function test_probe_skips_when_live_flag_disabled(): void
    {
        config(['snitch.nanogpt.api_key' => 'test-key']);

        $exit = Artisan::call('snitch:probe-video-analysis', [
            'url' => 'https://cdn.example.com/reel.mp4',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SNITCH_LIVE_VIDEO', Artisan::output());
    }
}
