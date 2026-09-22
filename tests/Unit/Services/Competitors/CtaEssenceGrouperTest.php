<?php

namespace Tests\Unit\Services\Competitors;

use App\Services\Analysis\NanoGptClient;
use App\Services\Competitors\CtaEssenceGrouper;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class CtaEssenceGrouperTest extends TestCase
{
    #[Test]
    public function test_model_groups_real_lines_and_drops_invented_phrases(): void
    {
        config([
            'snitch.cta_essence.force' => true,
            'snitch.nanogpt.api_key' => 'test-key',
        ]);

        $listen = 'listen or watch the full podcast recorded at the studio';
        $watch = 'watch the full episode on youtube now';
        $comment = 'comment guide and we will send the checklist';

        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->once())
            ->method('chatJson')
            ->willReturn([
                'groups' => [
                    [
                        'label' => 'watch the full episode',
                        'phrases' => [$listen, $watch, 'this phrase was not analysed'],
                    ],
                    [
                        'label' => 'Comment for the guide',
                        'phrases' => [$comment],
                    ],
                ],
            ]);
        $this->app->instance(NanoGptClient::class, $nano);

        $grouped = app(CtaEssenceGrouper::class)->group([
            $listen => 2,
            $watch => 3,
            $comment => 1,
        ]);

        $this->assertSame('Watch the full episode', $grouped[0]['term']);
        $this->assertSame(5, $grouped[0]['count']);
        $this->assertSame(
            ['Watch the full episode on youtube now', 'Listen or watch the full podcast recorded at the studio'],
            array_column($grouped[0]['lines'], 'text'),
        );
        $this->assertSame('Comment for the guide', $grouped[1]['term']);
        $this->assertSame($comment, mb_strtolower($grouped[1]['lines'][0]['text']));

        app(CtaEssenceGrouper::class)->group([
            $listen => 2,
            $watch => 3,
            $comment => 1,
        ]);
    }

    #[Test]
    public function test_failed_model_keeps_each_line_under_a_short_label(): void
    {
        config([
            'snitch.cta_essence.force' => true,
            'snitch.nanogpt.api_key' => 'test-key',
        ]);

        $phrase = 'listen or watch the full podcast recorded at the studio tonight';

        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->once())
            ->method('chatJson')
            ->willThrowException(new RuntimeException('NanoGPT request failed'));
        $this->app->instance(NanoGptClient::class, $nano);

        $grouped = app(CtaEssenceGrouper::class)->group([
            $phrase => 4,
        ]);

        $this->assertSame('Listen or watch the full podcast', $grouped[0]['term']);
        $this->assertSame(4, $grouped[0]['count']);
        $this->assertSame(
            'Listen or watch the full podcast recorded at the studio tonight',
            $grouped[0]['lines'][0]['text'],
        );
    }
}
