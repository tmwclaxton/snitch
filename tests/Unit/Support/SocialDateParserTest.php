<?php

namespace Tests\Unit\Support;

use App\Support\SocialDateParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SocialDateParserTest extends TestCase
{
    public function test_parses_unix_and_iso(): void
    {
        $this->assertSame(
            CarbonImmutable::createFromTimestamp(1_700_000_000)->toIso8601String(),
            SocialDateParser::toIso8601(1_700_000_000),
        );

        $this->assertNotNull(SocialDateParser::toIso8601('2026-01-05T12:00:00Z'));
        $this->assertNull(SocialDateParser::toIso8601(''));
        $this->assertNull(SocialDateParser::toIso8601(null));
    }

    public function test_parses_chinese_absolute_date_text(): void
    {
        $this->assertSame(
            '2026-01-05T00:00:00+00:00',
            SocialDateParser::toIso8601('2026年1月5日'),
        );
    }

    public function test_parses_english_and_chinese_relative(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10T12:00:00Z'));

        $this->assertSame(
            '2026-01-10T12:00:00+00:00',
            SocialDateParser::toIso8601('7 months ago'),
        );
        $this->assertSame(
            '2026-01-10T12:00:00+00:00',
            SocialDateParser::toIso8601('7个月前'),
        );
        $this->assertSame(
            '2026-08-08T12:00:00+00:00',
            SocialDateParser::toIso8601('2 days ago'),
        );

        CarbonImmutable::setTestNow();
    }
}
