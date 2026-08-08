<?php

namespace Tests\Feature;

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
}
