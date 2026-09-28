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
    public function test_model_groups_reclassify_catch_all_phrases_onto_real_labels(): void
    {
        config([
            'snitch.cta_essence.force' => true,
            'snitch.nanogpt.api_key' => 'test-key',
        ]);

        $listen = 'listen or watch the full podcast recorded at the studio';
        $watch = 'watch the full episode on youtube now';
        $comment = 'comment guide and we will send the checklist';
        $join = 'join challenge or event this weekend';
        $resource = 'check out local resource near you';

        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->once())
            ->method('chatJson')
            ->willReturn([
                'groups' => [
                    [
                        'label' => 'Other',
                        'phrases' => [$listen, $watch, $join, $resource, 'this phrase was not analysed'],
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
            $join => 4,
            $resource => 2,
        ]);

        $terms = array_column($grouped, 'term');
        $this->assertContains('Comment a keyword', $terms);
        $this->assertContains('Join the event', $terms);
        $this->assertContains('Link in bio', $terms);
        $this->assertContains('No clear ask', $terms);
        $this->assertNotContains('Other', $terms);
        $this->assertNotContains('Watch the full episode', $terms);
        $this->assertNotContains('Comment for the guide', $terms);

        $commentRow = collect($grouped)->firstWhere('term', 'Comment a keyword');
        $this->assertSame(1, $commentRow['count']);
        $this->assertSame($comment, mb_strtolower($commentRow['lines'][0]['text']));

        $joinRow = collect($grouped)->firstWhere('term', 'Join the event');
        $this->assertSame(4, $joinRow['count']);

        $linkRow = collect($grouped)->firstWhere('term', 'Link in bio');
        $this->assertSame(2, $linkRow['count']);

        $noAskRow = collect($grouped)->firstWhere('term', 'No clear ask');
        $this->assertSame(5, $noAskRow['count']);
    }

    #[Test]
    public function test_failed_model_classifies_phrases_onto_fixed_labels(): void
    {
        config([
            'snitch.cta_essence.force' => true,
            'snitch.nanogpt.api_key' => 'test-key',
        ]);

        $phrase = 'comment YES and we will send the checklist tonight';

        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->once())
            ->method('chatJson')
            ->willThrowException(new RuntimeException('NanoGPT request failed'));
        $this->app->instance(NanoGptClient::class, $nano);

        $grouped = app(CtaEssenceGrouper::class)->group([
            $phrase => 4,
        ]);

        $this->assertSame('Comment a keyword', $grouped[0]['term']);
        $this->assertSame(4, $grouped[0]['count']);
        $this->assertSame(
            'Comment YES and we will send the checklist tonight',
            $grouped[0]['lines'][0]['text'],
        );
    }

    #[Test]
    public function test_canonical_label_maps_garbled_free_text(): void
    {
        $grouper = app(CtaEssenceGrouper::class);

        $this->assertSame('Tag a friend', $grouper->canonicalLabel('Comment which friend luxury'));
        $this->assertSame('Join the event', $grouper->canonicalLabel('Get involved in area'));
        $this->assertSame('Join the event', $grouper->canonicalLabel('Book a table'));
        $this->assertSame('Join the event', $grouper->canonicalLabel('Join challenge or event'));
        $this->assertSame('Link in bio', $grouper->canonicalLabel('Check out local resource'));
        $this->assertSame('Link in bio', $grouper->canonicalLabel('Grab ticket via bio'));
        $this->assertSame('Comment a keyword', $grouper->canonicalLabel('Comment to receive details'));
        $this->assertSame('No clear ask', $grouper->canonicalLabel('Other asks'));
        $this->assertSame('No clear ask', $grouper->canonicalLabel('Swipe to see content'));
        $this->assertSame('No clear ask', $grouper->canonicalLabel('Watch the full episode'));
        $this->assertSame('Save this', $grouper->canonicalLabel('Save for later'));
        $this->assertSame('Ask a question', $grouper->canonicalLabel('Drop your question below'));
    }
}
