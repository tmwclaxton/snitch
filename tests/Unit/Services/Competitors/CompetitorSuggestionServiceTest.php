<?php

namespace Tests\Unit\Services\Competitors;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Competitors\CompetitorSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CompetitorSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_propose_parses_nanogpt_competitors_json(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.model' => 'deepseek/deepseek-v4-flash',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'competitors' => [
                                    [
                                        'name' => 'Instrumentl',
                                        'handles' => [
                                            'instagram' => 'instrumentl',
                                            'tiktok' => null,
                                            'facebook' => 'instrumentl',
                                            'linkedin' => 'instrumentl',
                                        ],
                                    ],
                                    [
                                        'name' => 'GrantWatch',
                                        'handles' => [
                                            'instagram' => '@grantwatch',
                                            'tiktok' => '',
                                            'facebook' => null,
                                            'linkedin' => 'grantwatch',
                                        ],
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'description' => 'AI grant writing assistant',
            'own_handles' => ['instagram' => '@grantgunner_official'],
        ]);

        $service = new CompetitorSuggestionService(
            app(NanoGptClient::class),
            $this->createMock(PlatformAdapterManager::class),
        );

        $candidates = $service->propose($brand);

        // One best handle per org, preferring Facebook when present.
        $this->assertSame([
            ['name' => 'Instrumentl', 'platform' => 'facebook', 'handle' => 'instrumentl'],
            ['name' => 'GrantWatch', 'platform' => 'instagram', 'handle' => 'grantwatch'],
        ], $candidates);
    }

    public function test_verify_filters_own_tracked_unresolved_and_keeps_resolved(): void
    {
        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'own_handles' => [
                'instagram' => '@grantgunner_official',
                'linkedin' => '@grantgunner',
            ],
        ]);

        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'alreadytracked',
        ]);

        $instagram = Mockery::mock(PlatformAdapter::class);
        $instagram->shouldReceive('resolveProfile')
            ->with('instrumentl')
            ->once()
            ->andReturn([
                'platform' => Platform::Instagram,
                'handle' => 'instrumentl',
                'url' => 'https://instagram.com/instrumentl',
                'external_id' => 'ig-1',
                'avatar' => 'https://cdn.example.com/instrumentl.jpg',
                'display_name' => 'Instrumentl',
            ]);
        $instagram->shouldReceive('resolveProfile')
            ->with('grantgunner_official')
            ->never();
        $instagram->shouldReceive('resolveProfile')
            ->with('alreadytracked')
            ->never();
        $instagram->shouldReceive('resolveProfile')
            ->with('ghostbrand')
            ->once()
            ->andReturn([
                'platform' => Platform::Instagram,
                'handle' => 'ghostbrand',
                'url' => 'https://instagram.com/ghostbrand',
                'external_id' => null,
                'avatar' => null,
                'display_name' => 'ghostbrand',
            ]);

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->with('instagram')->andReturn($instagram);

        $service = new CompetitorSuggestionService(
            $this->createMock(NanoGptClient::class),
            $manager,
        );

        $suggestions = $service->verify([
            ['name' => 'Instrumentl', 'platform' => 'instagram', 'handle' => 'instrumentl'],
            ['name' => 'Self', 'platform' => 'instagram', 'handle' => 'grantgunner_official'],
            ['name' => 'Tracked', 'platform' => 'instagram', 'handle' => 'alreadytracked'],
            ['name' => 'Ghost', 'platform' => 'instagram', 'handle' => 'ghostbrand'],
            ['name' => 'GrantGunner', 'platform' => 'instagram', 'handle' => 'somethingelse'],
        ], $brand);

        $this->assertCount(1, $suggestions);
        $this->assertSame('instrumentl', $suggestions[0]['handle']);
        $this->assertSame('Instrumentl', $suggestions[0]['display_name']);
        $this->assertSame('https://cdn.example.com/instrumentl.jpg', $suggestions[0]['avatar']);
    }

    public function test_suggest_returns_capped_verified_rows(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.max_suggestions' => 2,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'competitors' => [
                                    [
                                        'name' => 'A',
                                        'handles' => ['instagram' => 'aaa', 'tiktok' => null, 'facebook' => null, 'linkedin' => null],
                                    ],
                                    [
                                        'name' => 'B',
                                        'handles' => ['instagram' => 'bbb', 'tiktok' => null, 'facebook' => null, 'linkedin' => null],
                                    ],
                                    [
                                        'name' => 'C',
                                        'handles' => ['instagram' => 'ccc', 'tiktok' => null, 'facebook' => null, 'linkedin' => null],
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'Loaf Local',
            'own_handles' => [],
        ]);

        $instagram = Mockery::mock(PlatformAdapter::class);
        $instagram->shouldReceive('resolveProfile')->andReturnUsing(function (string $handle): array {
            return [
                'platform' => Platform::Instagram,
                'handle' => $handle,
                'url' => "https://instagram.com/{$handle}",
                'external_id' => "id-{$handle}",
                'avatar' => null,
                'display_name' => strtoupper($handle),
            ];
        });

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->with('instagram')->andReturn($instagram);

        $service = new CompetitorSuggestionService(app(NanoGptClient::class), $manager);
        $suggestions = $service->suggest($brand);

        $this->assertCount(2, $suggestions);
        $this->assertSame(['aaa', 'bbb'], array_column($suggestions, 'handle'));
    }

    public function test_grantgunner_live_eval_fixture_passes_quality_bar(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.platforms' => ['facebook', 'instagram'],
            'snitch.competitor_suggest.max_suggestions' => 8,
        ]);

        /** @var array{nanogpt_competitors: array<string, mixed>, suggestions: list<array<string, mixed>>} $fixture */
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/competitors/grantgunner-suggest.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        Http::preventStrayRequests();
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode($fixture['nanogpt_competitors'], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'description' => 'AI grant discovery and application platform',
            'own_handles' => [
                'instagram' => '@grantgunner_official',
                'facebook' => '@61588602791318',
                'linkedin' => '@grantgunner',
            ],
        ]);

        $facebook = Mockery::mock(PlatformAdapter::class);
        $facebook->shouldReceive('resolveProfile')->andReturnUsing(function (string $handle) use ($fixture): array {
            foreach ($fixture['suggestions'] as $row) {
                if (($row['platform'] ?? null) === 'facebook' && ($row['handle'] ?? null) === $handle) {
                    return [
                        'platform' => Platform::Facebook,
                        'handle' => $handle,
                        'url' => (string) $row['url'],
                        'external_id' => 'fb-'.$handle,
                        'avatar' => $row['avatar'],
                        'display_name' => (string) $row['display_name'],
                    ];
                }
            }

            return [
                'platform' => Platform::Facebook,
                'handle' => $handle,
                'url' => "https://facebook.com/{$handle}",
                'external_id' => null,
                'avatar' => null,
                'display_name' => $handle,
            ];
        });

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->with('facebook')->andReturn($facebook);
        $manager->shouldReceive('for')->with('instagram')->andReturn(Mockery::mock(PlatformAdapter::class));

        $suggestions = (new CompetitorSuggestionService(app(NanoGptClient::class), $manager))->suggest($brand);

        $this->assertGreaterThanOrEqual(5, count($suggestions));
        $this->assertLessThanOrEqual(8, count($suggestions));

        $handles = array_column($suggestions, 'handle');
        $this->assertNotContains('grantgunner', array_map(strtolower(...), $handles));
        $this->assertNotContains('grantgunner_official', array_map(strtolower(...), $handles));
        $this->assertNotContains('grantgunner_local', array_map(strtolower(...), $handles));

        foreach ($suggestions as $row) {
            $this->assertNotSame('', (string) $row['display_name']);
            $this->assertNotNull($row['avatar']);
            $this->assertDoesNotMatchRegularExpression('/(_local|tips)$/i', (string) $row['handle']);
        }

        $this->assertSame(
            array_column($fixture['suggestions'], 'handle'),
            array_column($suggestions, 'handle'),
        );
    }
}
