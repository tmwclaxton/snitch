<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TrustProxiesTest extends TestCase
{
    public function test_forwarded_proto_is_trusted_behind_cloudflare_tunnel(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.10',
        ])->get('/up');

        $response->assertOk();
        $this->assertTrue(request()->secure());
        $this->assertSame('203.0.113.10', request()->ip());
    }

    public function test_forwarded_host_does_not_rewrite_absolute_urls(): void
    {
        config(['app.url' => 'https://www.snitchsocial.net']);
        URL::forceRootUrl('https://www.snitchsocial.net');
        URL::forceScheme('https');

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'www.snitchsocial.net',
        ])->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example',
        ])->get('/sitemap.xml');

        $response->assertOk();
        $response->assertSee('https://www.snitchsocial.net', false);
        $response->assertDontSee('https://evil.example', false);
        $this->assertSame('www.snitchsocial.net', request()->getHost());
    }

    public function test_authenticate_redirect_stays_on_app_url_when_forwarded_host_spoofed(): void
    {
        config(['app.url' => 'https://www.snitchsocial.net']);
        URL::forceRootUrl('https://www.snitchsocial.net');
        URL::forceScheme('https');

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'www.snitchsocial.net',
        ])->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example',
        ])->get('/authenticate');

        $response->assertRedirect('https://www.snitchsocial.net/login');
    }
}
