<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class WayfinderGenerateUrlsTest extends TestCase
{
    public function test_wayfinder_generate_emits_path_only_urls_when_app_url_is_absolute(): void
    {
        $output = storage_path('framework/testing/wayfinder-'.uniqid('', true));
        File::deleteDirectory($output);

        try {
            $result = Process::path(base_path())
                ->env([
                    ...getenv(),
                    'APP_ENV' => 'local',
                    'APP_URL' => 'https://www.snitchsocial.net',
                ])
                ->run([
                    PHP_BINARY,
                    'artisan',
                    'wayfinder:generate',
                    '--path='.$output,
                    '--skip-actions',
                    '--no-interaction',
                ]);

            $this->assertTrue($result->successful(), $result->errorOutput().$result->output());

            $routes = File::get($output.'/routes/index.ts');

            $this->assertStringContainsString("url: '/dashboard'", $routes);
            $this->assertStringNotContainsString('http://localhost', $routes);
            $this->assertStringNotContainsString('https://www.snitchsocial.net', $routes);
        } finally {
            File::deleteDirectory($output);
        }
    }
}
