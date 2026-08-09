<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GenerateBlogPostCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_create_posts(): void
    {
        $exit = Artisan::call('blog:generate', [
            '--dry-run' => true,
            '--topic' => 'How to remake winning TikTok hooks',
            '--cluster' => 'tiktok-hooks',
            '--skip-image' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseCount('blogs', 0);
        $this->assertStringContainsString('Dry run', Artisan::output());
    }
}
