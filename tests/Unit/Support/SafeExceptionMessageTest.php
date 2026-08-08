<?php

namespace Tests\Unit\Support;

use App\Support\SafeExceptionMessage;
use RuntimeException;
use Tests\TestCase;

class SafeExceptionMessageTest extends TestCase
{
    public function test_redacts_query_string_tokens(): void
    {
        $message = SafeExceptionMessage::forUsers(new RuntimeException(
            'cURL error 28: timed out for https://api.apify.com/v2/acts/x/run?token=SECRET_APIFY_TOKEN_VALUE',
        ));

        $this->assertStringContainsString('token=[redacted]', $message);
        $this->assertStringNotContainsString('SECRET_APIFY_TOKEN_VALUE', $message);
    }

    public function test_redacts_bearer_tokens(): void
    {
        $message = SafeExceptionMessage::forUsers(new RuntimeException(
            'Upstream rejected Authorization: Bearer super-secret-token-value',
        ));

        $this->assertStringContainsString('Bearer [redacted]', $message);
        $this->assertStringNotContainsString('super-secret-token-value', $message);
    }

    public function test_uses_fallback_when_exception_missing(): void
    {
        $this->assertSame('Sync failed.', SafeExceptionMessage::forUsers(null, 'Sync failed.'));
    }
}
