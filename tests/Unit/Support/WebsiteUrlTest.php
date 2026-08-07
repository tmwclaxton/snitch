<?php

namespace Tests\Unit\Support;

use App\Support\WebsiteUrl;
use PHPUnit\Framework\TestCase;

class WebsiteUrlTest extends TestCase
{
    public function test_prepends_https_when_scheme_missing(): void
    {
        $this->assertSame('https://www.grantgunner.org', WebsiteUrl::normalize('www.grantgunner.org'));
        $this->assertSame('https://grantgunner.org', WebsiteUrl::normalize('grantgunner.org'));
    }

    public function test_keeps_existing_scheme(): void
    {
        $this->assertSame('https://www.grantgunner.org', WebsiteUrl::normalize('https://www.grantgunner.org'));
        $this->assertSame('http://example.com', WebsiteUrl::normalize('http://example.com'));
    }

    public function test_empty_values_become_null(): void
    {
        $this->assertNull(WebsiteUrl::normalize(null));
        $this->assertNull(WebsiteUrl::normalize(''));
        $this->assertNull(WebsiteUrl::normalize('   '));
    }

    public function test_host_must_include_a_dot(): void
    {
        $this->assertTrue(WebsiteUrl::hasValidHost('https://www.grantgunner.org'));
        $this->assertFalse(WebsiteUrl::hasValidHost('https://not-a-url'));
        $this->assertFalse(WebsiteUrl::hasValidHost('not-a-url'));
    }
}
