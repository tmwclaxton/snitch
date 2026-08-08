<?php

namespace Tests\Feature\Analysis;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProbeCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_apify_probe_skips_without_live_flag(): void
    {
        $exit = Artisan::call('snitch:probe-apify', [
            'platform' => 'instagram',
            'handle' => 'rivalbakery',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SNITCH_LIVE_APIFY', Artisan::output());
    }

    public function test_e2e_probe_skips_without_live_flag(): void
    {
        $exit = Artisan::call('snitch:probe-e2e', [
            '--user' => 'ada@example.com',
            '--handle' => 'rivalbakery',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SNITCH_LIVE_E2E', Artisan::output());
    }

    public function test_analysis_matrix_probe_skips_without_live_flag(): void
    {
        $exit = Artisan::call('snitch:probe-analysis-matrix', [
            '--platform' => ['instagram'],
            '--url' => ['https://cdn.example.com/reel.mp4'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SNITCH_LIVE_ANALYSIS_MATRIX', Artisan::output());
    }

    public function test_winners_probe_prints_for_user(): void
    {
        $user = User::factory()->create(['email' => 'probe@example.com']);

        $exit = Artisan::call('snitch:probe-winners', [
            'user' => $user->email,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Winner rules preset', Artisan::output());
    }
}
