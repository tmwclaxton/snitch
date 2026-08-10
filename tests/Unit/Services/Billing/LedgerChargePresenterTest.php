<?php

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\LedgerChargePresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LedgerChargePresenterTest extends TestCase
{
    private LedgerChargePresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new LedgerChargePresenter;
    }

    #[DataProvider('descriptionProvider')]
    public function test_descriptions_from_action_and_meta(string $action, array $meta, string $expected): void
    {
        $this->assertSame($expected, $this->presenter->description($action, $meta));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function descriptionProvider(): array
    {
        return [
            'youtube short' => [
                'analyze.post',
                ['platform' => 'youtube', 'post_type' => 'reel', 'post_id' => 9],
                'Analyzed YouTube Short',
            ],
            'instagram reel' => [
                'analyze.post',
                ['platform' => 'instagram', 'post_type' => 'reel'],
                'Analyzed Instagram Reel',
            ],
            'sync competitor' => [
                'sync.account',
                [
                    'platform' => 'instagram',
                    'account_kind' => 'competitor',
                    'handle' => 'nike',
                    'tracked_account_id' => 3,
                ],
                'Synced Instagram competitor @nike',
            ],
            'suggest competitors' => [
                'competitors.suggest',
                ['suggest_id' => 'abc', 'kind' => 'search'],
                'Suggested competitors',
            ],
            'brand autofill' => [
                'brand.autofill',
                ['autofill_id' => 'x'],
                'Brand autofill',
            ],
            'meta override' => [
                'analyze.post',
                ['description' => 'Custom line', 'post_id' => 1],
                'Custom line',
            ],
            'legacy action only' => [
                'analyze.post',
                [],
                'Analyzed post',
            ],
            'claim bonus' => [
                'claim_bonus',
                [],
                'Welcome credits',
            ],
            'indexed analysis' => [
                'embed.analysis',
                ['post_analysis_id' => 3, 'post_id' => 9],
                'Indexed post analysis',
            ],
        ];
    }

    public function test_post_link_preferred_over_tracked_account(): void
    {
        $link = $this->presenter->link('analyze.post', [
            'post_id' => 12,
            'tracked_account_id' => 4,
            'handle' => 'acme',
        ]);

        $this->assertSame([
            'type' => 'post',
            'id' => 12,
            'label' => 'View post',
        ], $link);
    }

    public function test_embed_analysis_links_via_post_id(): void
    {
        $link = $this->presenter->link('embed.analysis', [
            'post_analysis_id' => 3,
            'post_id' => 9,
        ]);

        $this->assertSame([
            'type' => 'post',
            'id' => 9,
            'label' => 'View post',
        ], $link);
    }

    public function test_tracked_account_link_uses_handle_label(): void
    {
        $link = $this->presenter->link('sync.account', [
            'tracked_account_id' => 4,
            'handle' => '@acme',
        ]);

        $this->assertSame([
            'type' => 'tracked_account',
            'id' => 4,
            'label' => '@acme',
        ], $link);
    }

    public function test_discovery_actions_link_to_index_pages(): void
    {
        $this->assertSame(
            ['type' => 'competitors', 'label' => 'Competitors'],
            $this->presenter->link('competitors.suggest', ['suggest_id' => 's1']),
        );

        $this->assertSame(
            ['type' => 'influencers', 'label' => 'Influencers'],
            $this->presenter->link('influencers.find', ['run_id' => 'r1']),
        );

        $this->assertSame(
            ['type' => 'brand', 'label' => 'Brand'],
            $this->presenter->link('brand.autofill', []),
        );
    }
}
