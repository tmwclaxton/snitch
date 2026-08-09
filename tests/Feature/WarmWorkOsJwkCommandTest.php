<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WarmWorkOsJwkCommandTest extends TestCase
{
    public function test_warms_workos_jwk_cache(): void
    {
        config([
            'services.workos.client_id' => 'client_test',
            'services.workos.secret' => 'sk_test',
            'services.workos.redirect_url' => 'https://www.snitchsocial.net/authenticate',
        ]);

        Http::fake([
            'https://api.workos.com/*' => Http::response([
                'keys' => [
                    ['kty' => 'RSA', 'kid' => 'test', 'n' => 'n', 'e' => 'AQAB'],
                ],
            ]),
        ]);

        Cache::forget('workos:jwk');

        $this->artisan('snitch:warm-workos-jwk')
            ->assertSuccessful();

        $cached = Cache::get('workos:jwk');
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('keys', $cached);
    }

    public function test_skips_when_workos_is_not_configured(): void
    {
        config([
            'services.workos.client_id' => null,
            'services.workos.secret' => null,
        ]);

        Http::fake();

        $this->artisan('snitch:warm-workos-jwk')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_dockerfile_prefers_ipv4_and_uses_php_fpm(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertNotFalse($dockerfile);
        $this->assertStringContainsString('php:8.5-fpm-bookworm', $dockerfile);
        $this->assertStringContainsString('precedence :ffff:0:0/96', $dockerfile);
        $this->assertStringContainsString('nginx', $dockerfile);
        $this->assertStringNotContainsString('artisan serve', $dockerfile);
    }
}
