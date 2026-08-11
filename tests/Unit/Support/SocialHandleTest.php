<?php

namespace Tests\Unit\Support;

use App\Enums\Platform;
use App\Support\SocialHandle;
use PHPUnit\Framework\TestCase;

class SocialHandleTest extends TestCase
{
    public function test_is_weak_rejects_generic_tokens_and_short_handles(): void
    {
        $this->assertTrue(SocialHandle::isWeak('content'));
        $this->assertTrue(SocialHandle::isWeak('CONTENT'));
        $this->assertTrue(SocialHandle::isWeak('@feed', Platform::Instagram));
        $this->assertTrue(SocialHandle::isWeak('ab'));
    }

    public function test_is_weak_rejects_numeric_facebook_ids(): void
    {
        $this->assertTrue(SocialHandle::isWeak('100081639724957', Platform::Facebook));
    }

    public function test_is_weak_allows_real_handles(): void
    {
        $this->assertFalse(SocialHandle::isWeak('farmbrite'));
        $this->assertFalse(SocialHandle::isWeak('rivalbakery', Platform::Instagram));
    }
}
