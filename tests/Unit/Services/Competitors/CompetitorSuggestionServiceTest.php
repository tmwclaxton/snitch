<?php

namespace Tests\Unit\Services\Competitors;

use App\Enums\Platform;
use App\Exceptions\InsufficientCompetitorSuggestionsException;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Competitors\CompetitorSuggestionService;
use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CompetitorSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(
        ?FirecrawlClient $firecrawl = null,
        ?NanoGptClient $nanoGpt = null,
        ?PlatformAdapterManager $adapters = null,
        ?ApifyClient $apify = null,
    ): CompetitorSuggestionService {
        return new CompetitorSuggestionService(
            $firecrawl ?? $this->createMock(FirecrawlClient::class),
            $nanoGpt ?? $this->createMock(NanoGptClient::class),
            $adapters ?? $this->createMock(PlatformAdapterManager::class),
            $apify ?? app(ApifyClient::class),
        );
    }

    /**
     * @return list<array{url: string, title: string, description: string}>
     */
    private function sampleHits(): array
    {
        return [
            [
                'url' => 'https://www.instrumentl.com',
                'title' => 'Instrumentl - Grant Discovery',
                'description' => 'Find and win more grants with Instrumentl.',
            ],
            [
                'url' => 'https://www.grantwatch.com',
                'title' => 'GrantWatch',
                'description' => 'Largest grant listing directory.',
            ],
            [
                'url' => 'https://instagram.com/instrumentl',
                'title' => 'Instrumentl on Instagram',
                'description' => 'Official Instagram.',
            ],
            [
                'url' => 'https://facebook.com/GrantWatch',
                'title' => 'GrantWatch Facebook',
                'description' => 'GrantWatch page.',
            ],
            [
                'url' => 'https://linkedin.com/company/candid-org',
                'title' => 'Candid on LinkedIn',
                'description' => 'Foundation Directory Online.',
            ],
            [
                'url' => 'https://tiktok.com/@grantsforgood',
                'title' => 'Grants for Good on TikTok',
                'description' => 'Grant tips for nonprofits on TikTok.',
            ],
            [
                'url' => 'https://youtube.com/@GrantWatch',
                'title' => 'GrantWatch YouTube',
                'description' => 'GrantWatch Shorts and grant tips.',
            ],
            [
                'url' => 'https://www.submittable.com',
                'title' => 'Submittable',
                'description' => 'Submission and grant management software.',
            ],
        ];
    }

    public function test_search_queries_include_per_platform_site_targets(): void
    {
        config([
            'snitch.competitor_suggest.platforms' => ['instagram', 'tiktok', 'youtube', 'linkedin', 'facebook'],
        ]);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'description' => 'AI grant writing assistant for nonprofits and charities',
            'website' => 'https://grantgunner.org',
        ]);

        $service = $this->makeService();

        $queries = $service->searchQueries($brand);

        $this->assertTrue(collect($queries)->contains(fn (string $q): bool => str_contains($q, 'site:instagram.com')));
        $this->assertTrue(collect($queries)->contains(fn (string $q): bool => str_contains($q, 'site:tiktok.com')));
        $this->assertTrue(collect($queries)->contains(fn (string $q): bool => str_contains($q, 'site:youtube.com')));
        $this->assertTrue(collect($queries)->contains(
            fn (string $q): bool => str_contains($q, 'site:linkedin.com/company')
                && str_contains($q, 'site:linkedin.com/in'),
        ));
        $this->assertTrue(collect($queries)->contains(fn (string $q): bool => str_contains($q, 'site:facebook.com')));
        $this->assertTrue(collect($queries)->contains(
            fn (string $q): bool => str_contains($q, 'grant') && str_contains($q, 'site:tiktok.com'),
        ));
        $this->assertTrue(collect($queries)->contains('GrantGunner competitors alternatives'));
        $this->assertFalse(collect($queries)->contains(
            fn (string $q): bool => str_starts_with($q, 'GrantGunner site:tiktok.com'),
        ));
        $this->assertFalse(collect($queries)->contains(
            fn (string $q): bool => str_contains($q, 'site:linkedin.com/company OR site:instagram.com OR site:facebook.com'),
        ));
    }

    public function test_search_queries_skip_weak_brand_name_for_snitch(): void
    {
        config([
            'snitch.competitor_suggest.platforms' => ['instagram', 'tiktok', 'youtube', 'linkedin', 'facebook'],
        ]);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'Snitch',
            'description' => 'Social media intelligence platform for tracking competitors and influencers',
            'website' => 'https://www.snitchsocial.net',
        ]);

        $service = $this->makeService();

        $this->assertTrue($service->isWeakBrandName('Snitch'));
        $this->assertFalse($service->isWeakBrandName('GrantGunner'));
        $this->assertFalse($service->isWeakBrandName('Brandwatch'));

        $queries = $service->searchQueries($brand);

        $this->assertFalse(collect($queries)->contains('Snitch competitors alternatives'));
        $this->assertFalse(collect($queries)->contains('Snitch vs similar tools brands'));
        $this->assertTrue(collect($queries)->contains(
            fn (string $q): bool => str_contains($q, 'competitors alternatives software tools'),
        ));
        $this->assertTrue(collect($queries)->contains('competitors alternatives related:snitchsocial.net'));
        $this->assertTrue(collect($queries)->contains(
            fn (string $q): bool => str_contains($q, 'site:tiktok.com')
                && ! str_starts_with($q, 'Snitch '),
        ));
        $this->assertNotSame('', $service->nicheSearchPhrase($brand));
    }

    public function test_search_requires_brand_description(): void
    {
        config(['snitch.firecrawl.api_key' => 'fc-test']);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'Snitch',
            'description' => null,
            'website' => 'https://www.snitchsocial.net',
        ]);

        $service = $this->makeService();

        $this->assertSame('', $service->nicheSearchPhrase($brand));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('brand description');

        $service->search($brand);
    }

    public function test_verify_rejects_numeric_facebook_handles_even_if_resolved(): void
    {
        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'own_handles' => [],
        ]);

        $facebook = Mockery::mock(PlatformAdapter::class);
        $facebook->shouldReceive('resolveProfile')
            ->with('100081639724957')
            ->never();
        $facebook->shouldReceive('resolveProfile')
            ->with('vanitypage')
            ->once()
            ->andReturn([
                'platform' => Platform::Facebook,
                'handle' => '1000999888777',
                'url' => 'https://facebook.com/1000999888777',
                'external_id' => '1000999888777',
                'avatar' => null,
                'display_name' => 'Still Numeric',
            ]);
        $facebook->shouldReceive('resolveProfile')
            ->with('NamedGrantPage')
            ->once()
            ->andReturn([
                'platform' => Platform::Facebook,
                'handle' => 'NamedGrantPage',
                'url' => 'https://facebook.com/NamedGrantPage',
                'external_id' => 'fb-named',
                'avatar' => null,
                'display_name' => 'Named Grant Page',
            ]);

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->with('facebook')->andReturn($facebook);

        $service = $this->makeService(adapters: $manager);

        $suggestions = $service->verify([
            ['name' => 'Junk', 'platform' => 'facebook', 'handle' => '100081639724957'],
            ['name' => 'Remapped', 'platform' => 'facebook', 'handle' => 'vanitypage'],
            ['name' => 'Named', 'platform' => 'facebook', 'handle' => 'NamedGrantPage'],
        ], $brand);

        $this->assertCount(1, $suggestions);
        $this->assertSame('NamedGrantPage', $suggestions[0]['handle']);
    }

    public function test_verify_prefers_tiktok_profile_display_name_over_video_titles(): void
    {
        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'own_handles' => [],
        ]);

        $tiktok = Mockery::mock(PlatformAdapter::class);
        $tiktok->shouldReceive('resolveProfile')
            ->with('jenthegrantguru')
            ->once()
            ->andReturn([
                'platform' => Platform::TikTok,
                'handle' => 'jenthegrantguru',
                'url' => 'https://tiktok.com/@jenthegrantguru',
                'external_id' => 'tt-jen',
                'avatar' => null,
                'display_name' => 'Jen the Grant Guru',
            ]);
        $tiktok->shouldReceive('resolveProfile')
            ->with('candiddotorg')
            ->once()
            ->andReturn([
                'platform' => Platform::TikTok,
                'handle' => 'candiddotorg',
                'url' => 'https://tiktok.com/@candiddotorg',
                'external_id' => 'tt-candid',
                'avatar' => null,
                'display_name' => 'Candid',
            ]);
        $tiktok->shouldReceive('resolveProfile')
            ->with('granttips')
            ->once()
            ->andReturn([
                'platform' => Platform::TikTok,
                'handle' => 'granttips',
                'url' => 'https://tiktok.com/@granttips',
                'external_id' => 'tt-tips',
                'avatar' => null,
                'display_name' => 'granttips',
            ]);

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->with('tiktok')->andReturn($tiktok);

        $service = $this->makeService(adapters: $manager);

        $suggestions = $service->verify([
            [
                'name' => 'Ultimate Guide to NGO Funding: Strategies, Grants, Fundraising ...',
                'platform' => 'tiktok',
                'handle' => 'jenthegrantguru',
            ],
            [
                'name' => 'Grants for Nonprofits - TikTok',
                'platform' => 'tiktok',
                'handle' => 'candiddotorg',
            ],
            [
                'name' => 'Grant writing tip: tailor your grant proposal...',
                'platform' => 'tiktok',
                'handle' => 'granttips',
            ],
        ], $brand);

        $byHandle = collect($suggestions)->keyBy('handle');

        $this->assertSame('Jen the Grant Guru', $byHandle['jenthegrantguru']['display_name']);
        $this->assertSame('Candid', $byHandle['candiddotorg']['display_name']);
        // Profile name equals handle; video-title candidate is rejected → handle fallback.
        $this->assertSame('granttips', $byHandle['granttips']['display_name']);
    }

    public function test_propose_strips_tiktok_suffixes_and_rejects_video_titles_from_hits(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.platforms' => ['tiktok'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'competitors' => [],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'description' => 'AI grant writing',
            'own_handles' => [],
        ]);

        $hits = [
            [
                'url' => 'https://tiktok.com/@candiddotorg',
                'title' => 'Candid - TikTok',
                'description' => 'Candid on TikTok.',
            ],
            [
                'url' => 'https://tiktok.com/@jenthegrantguru',
                'title' => 'Ultimate Guide to NGO Funding: Strategies, Grants, Fundraising ...',
                'description' => 'Video about NGO funding.',
            ],
            [
                'url' => 'https://www.tiktok.com/@instrumentl',
                'title' => 'Instrumentl | TikTok',
                'description' => 'Instrumentl channel.',
            ],
        ];

        $service = $this->makeService(nanoGpt: app(NanoGptClient::class));

        $candidates = $service->propose($brand, $hits);
        $byHandle = collect($candidates)->keyBy('handle');

        $this->assertSame('Candid', $byHandle['candiddotorg']['name']);
        $this->assertSame('Instrumentl', $byHandle['instrumentl']['name']);
        // Video/SEO title rejected in favor of the handle until resolveProfile runs.
        $this->assertSame('jenthegrantguru', $byHandle['jenthegrantguru']['name']);
    }

    public function test_propose_rejects_numeric_facebook_handles_from_hits_and_llm(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.platforms' => ['facebook', 'instagram'],
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
                                        'name' => 'Junk Page',
                                        'handles' => [
                                            'facebook' => '100081639724957',
                                            'instagram' => 'realgrantpage',
                                        ],
                                    ],
                                    [
                                        'name' => 'Named Page',
                                        'handles' => [
                                            'facebook' => 'NamedGrantPage',
                                            'instagram' => null,
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
            'description' => 'AI grant writing',
            'own_handles' => [],
        ]);

        $hits = [
            [
                'url' => 'https://facebook.com/100081639724957',
                'title' => 'Numeric Facebook',
                'description' => 'Junk id page.',
            ],
            [
                'url' => 'https://instagram.com/realgrantpage',
                'title' => 'Real IG',
                'description' => 'Real Instagram.',
            ],
        ];

        $service = $this->makeService(nanoGpt: app(NanoGptClient::class));

        $candidates = $service->propose($brand, $hits);
        $keys = array_map(
            fn (array $row): string => "{$row['platform']}:{$row['handle']}",
            $candidates,
        );

        $this->assertNotContains('facebook:100081639724957', $keys);
        $this->assertContains('facebook:NamedGrantPage', $keys);
        $this->assertContains('instagram:realgrantpage', $keys);
    }

    public function test_verify_soft_caps_facebook_while_other_platforms_remain(): void
    {
        config([
            'snitch.competitor_suggest.max_suggestions' => 6,
            'snitch.competitor_suggest.min_suggestions' => 6,
            'snitch.competitor_suggest.max_per_platform' => 2,
            'snitch.competitor_suggest.platforms' => ['instagram', 'tiktok', 'facebook'],
        ]);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'own_handles' => [],
        ]);

        $instagram = Mockery::mock(PlatformAdapter::class);
        $instagram->shouldReceive('resolveProfile')->andReturnUsing(fn (string $handle): array => [
            'platform' => Platform::Instagram,
            'handle' => $handle,
            'url' => "https://instagram.com/{$handle}",
            'external_id' => "ig-{$handle}",
            'avatar' => null,
            'display_name' => $handle,
        ]);

        $facebook = Mockery::mock(PlatformAdapter::class);
        $facebook->shouldReceive('resolveProfile')->andReturnUsing(fn (string $handle): array => [
            'platform' => Platform::Facebook,
            'handle' => $handle,
            'url' => "https://facebook.com/{$handle}",
            'external_id' => "fb-{$handle}",
            'avatar' => null,
            'display_name' => $handle,
        ]);

        $tiktok = Mockery::mock(PlatformAdapter::class);
        $tiktok->shouldReceive('resolveProfile')->andReturnUsing(fn (string $handle): array => [
            'platform' => Platform::TikTok,
            'handle' => $handle,
            'url' => "https://tiktok.com/@{$handle}",
            'external_id' => "tt-{$handle}",
            'avatar' => null,
            'display_name' => $handle,
        ]);

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->with('instagram')->andReturn($instagram);
        $manager->shouldReceive('for')->with('facebook')->andReturn($facebook);
        $manager->shouldReceive('for')->with('tiktok')->andReturn($tiktok);

        $service = $this->makeService(adapters: $manager);

        $suggestions = $service->verify([
            ['name' => 'FB1', 'platform' => 'facebook', 'handle' => 'fbone'],
            ['name' => 'FB2', 'platform' => 'facebook', 'handle' => 'fbtwo'],
            ['name' => 'FB3', 'platform' => 'facebook', 'handle' => 'fbthree'],
            ['name' => 'FB4', 'platform' => 'facebook', 'handle' => 'fbfour'],
            ['name' => 'IG1', 'platform' => 'instagram', 'handle' => 'igone'],
            ['name' => 'IG2', 'platform' => 'instagram', 'handle' => 'igtwo'],
            ['name' => 'TT1', 'platform' => 'tiktok', 'handle' => 'ttone'],
            ['name' => 'TT2', 'platform' => 'tiktok', 'handle' => 'tttwo'],
        ], $brand);

        $this->assertCount(6, $suggestions);
        $counts = array_count_values(array_column($suggestions, 'platform'));
        $this->assertSame(2, $counts['facebook'] ?? 0);
        $this->assertSame(2, $counts['instagram'] ?? 0);
        $this->assertSame(2, $counts['tiktok'] ?? 0);
    }

    public function test_propose_parses_nanogpt_competitors_json_with_multi_platform_rows(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.model' => 'deepseek/deepseek-v4-flash',
            'snitch.competitor_suggest.platforms' => ['facebook', 'instagram', 'linkedin'],
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
                                        'source' => 'Find and win more grants with Instrumentl.',
                                        'handles' => [
                                            'instagram' => 'instrumentl',
                                            'tiktok' => null,
                                            'facebook' => 'instrumentl',
                                            'linkedin' => 'instrumentl',
                                        ],
                                    ],
                                    [
                                        'name' => 'GrantWatch',
                                        'source' => 'Largest grant listing directory.',
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

        $service = $this->makeService(nanoGpt: app(NanoGptClient::class));

        $candidates = $service->propose($brand, $this->sampleHits());

        $keys = array_map(
            fn (array $row): string => "{$row['platform']}:{$row['handle']}",
            $candidates,
        );

        $this->assertContains('instagram:instrumentl', $keys);
        $this->assertContains('facebook:instrumentl', $keys);
        $this->assertContains('linkedin:instrumentl', $keys);
        $this->assertContains('instagram:grantwatch', $keys);
        $this->assertContains('linkedin:grantwatch', $keys);

        // Round-robin should not dump every Facebook row first.
        $firstPlatforms = array_column(array_slice($candidates, 0, 3), 'platform');
        $this->assertGreaterThan(1, count(array_unique($firstPlatforms)));
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

        $service = $this->makeService(adapters: $manager);

        $suggestions = $service->verify([
            ['name' => 'Instrumentl', 'platform' => 'instagram', 'handle' => 'instrumentl', 'source' => 'Grant discovery'],
            ['name' => 'Self', 'platform' => 'instagram', 'handle' => 'grantgunner_official'],
            ['name' => 'Tracked', 'platform' => 'instagram', 'handle' => 'alreadytracked'],
            ['name' => 'Ghost', 'platform' => 'instagram', 'handle' => 'ghostbrand'],
            ['name' => 'GrantGunner', 'platform' => 'instagram', 'handle' => 'somethingelse'],
        ], $brand, $this->sampleHits());

        $this->assertCount(1, $suggestions);
        $this->assertSame('instrumentl', $suggestions[0]['handle']);
        $this->assertSame('Instrumentl', $suggestions[0]['display_name']);
        $this->assertSame('https://cdn.example.com/instrumentl.jpg', $suggestions[0]['avatar']);
        $this->assertNotNull($suggestions[0]['source']);
    }

    public function test_verify_invokes_progress_callback_as_rows_verify(): void
    {
        config([
            'snitch.competitor_suggest.max_suggestions' => 3,
            'snitch.competitor_suggest.min_suggestions' => 1,
            'snitch.competitor_suggest.resolve_concurrency' => 1,
            'snitch.competitor_suggest.platforms' => ['instagram'],
        ]);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'own_handles' => [],
        ]);

        $instagram = Mockery::mock(PlatformAdapter::class);
        $instagram->shouldReceive('resolveProfile')
            ->twice()
            ->andReturn(
                [
                    'platform' => Platform::Instagram,
                    'handle' => 'alpha',
                    'url' => 'https://instagram.com/alpha',
                    'external_id' => '1',
                    'avatar' => null,
                    'display_name' => 'Alpha',
                ],
                [
                    'platform' => Platform::Instagram,
                    'handle' => 'beta',
                    'url' => 'https://instagram.com/beta',
                    'external_id' => '2',
                    'avatar' => null,
                    'display_name' => 'Beta',
                ],
            );

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->with('instagram')->andReturn($instagram);

        $progressCounts = [];
        $service = $this->makeService(adapters: $manager);
        $suggestions = $service->verify(
            [
                ['name' => 'Alpha', 'platform' => 'instagram', 'handle' => 'alpha', 'source' => null],
                ['name' => 'Beta', 'platform' => 'instagram', 'handle' => 'beta', 'source' => null],
            ],
            $brand,
            [],
            function (array $partial) use (&$progressCounts): void {
                $progressCounts[] = count($partial);
            },
        );

        $this->assertCount(2, $suggestions);
        $this->assertSame([1, 2], $progressCounts);
    }

    public function test_suggest_returns_capped_verified_rows_from_firecrawl_pipeline(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.max_suggestions' => 2,
            'snitch.competitor_suggest.min_suggestions' => 2,
            'snitch.competitor_suggest.platforms' => ['instagram'],
            'snitch.competitor_suggest.search_limit' => 5,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/search' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'url' => 'https://www.instrumentl.com',
                        'title' => 'Instrumentl',
                        'description' => 'Grant discovery software.',
                    ],
                ],
            ]),
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
            'description' => 'Neighborhood bakery',
            'website' => 'https://loaf.example',
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

        $service = $this->makeService(app(FirecrawlClient::class), app(NanoGptClient::class), $manager);
        $suggestions = $service->suggest($brand);

        $this->assertCount(2, $suggestions);
        $this->assertSame(['aaa', 'bbb'], array_column($suggestions, 'handle'));
    }

    public function test_suggest_fails_when_fewer_than_min_verified(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.min_suggestions' => 6,
            'snitch.competitor_suggest.max_suggestions' => 16,
            'snitch.competitor_suggest.platforms' => ['instagram'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/search' => Http::response([
                'success' => true,
                'data' => $this->sampleHits(),
            ]),
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'competitors' => [
                                    [
                                        'name' => 'Only One',
                                        'handles' => ['instagram' => 'onlyone'],
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
            'description' => 'AI grant writing',
            'own_handles' => [],
        ]);

        $instagram = Mockery::mock(PlatformAdapter::class);
        $instagram->shouldReceive('resolveProfile')->andReturn([
            'platform' => Platform::Instagram,
            'handle' => 'onlyone',
            'url' => 'https://instagram.com/onlyone',
            'external_id' => 'ig-1',
            'avatar' => null,
            'display_name' => 'Only One',
        ]);

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->with('instagram')->andReturn($instagram);

        $this->expectException(InsufficientCompetitorSuggestionsException::class);
        $this->expectExceptionMessage('need at least 6');

        $this->makeService(app(FirecrawlClient::class), app(NanoGptClient::class), $manager)
            ->suggest($brand);
    }

    public function test_grantgunner_live_eval_fixture_passes_quality_bar(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.competitor_suggest.platforms' => ['facebook', 'instagram', 'linkedin'],
            'snitch.competitor_suggest.max_suggestions' => 12,
            'snitch.competitor_suggest.min_suggestions' => 6,
            'snitch.competitor_suggest.max_per_platform' => 3,
            'snitch.competitor_suggest.max_resolves' => 32,
            'snitch.competitor_suggest.search_limit' => 8,
        ]);

        /** @var array{
         *     firecrawl_hits: list<array{url: string, title: string, description: string}>,
         *     nanogpt_competitors: array<string, mixed>,
         *     suggestions: list<array<string, mixed>>
         * } $fixture
         */
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/competitors/grantgunner-suggest.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/search' => Http::response([
                'success' => true,
                'data' => $fixture['firecrawl_hits'],
            ]),
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
            'website' => 'https://grantgunner.org',
            'own_handles' => [
                'instagram' => '@grantgunner_official',
                'facebook' => '@61588602791318',
                'linkedin' => '@grantgunner',
            ],
        ]);

        $byPlatform = [];

        foreach ($fixture['suggestions'] as $row) {
            $platform = (string) $row['platform'];
            $byPlatform[$platform][(string) $row['handle']] = $row;
        }

        $manager = Mockery::mock(PlatformAdapterManager::class);

        foreach (['facebook', 'instagram', 'linkedin', 'tiktok'] as $platform) {
            $adapter = Mockery::mock(PlatformAdapter::class);
            $adapter->shouldReceive('resolveProfile')->andReturnUsing(function (string $handleOrUrl) use ($platform, $byPlatform): array {
                $handle = $handleOrUrl;

                if (str_contains($handleOrUrl, 'linkedin.com/')) {
                    $path = (string) parse_url($handleOrUrl, PHP_URL_PATH);
                    $segments = array_values(array_filter(explode('/', trim($path, '/'))));
                    $handle = $segments[1] ?? basename($path);
                }

                $row = $byPlatform[$platform][$handle] ?? null;

                if ($row === null) {
                    return [
                        'platform' => Platform::from($platform),
                        'handle' => $handle,
                        'url' => "https://example.com/{$handle}",
                        'external_id' => null,
                        'avatar' => null,
                        'display_name' => $handle,
                    ];
                }

                return [
                    'platform' => Platform::from($platform),
                    'handle' => $handle,
                    'url' => (string) $row['url'],
                    'external_id' => "{$platform}-{$handle}",
                    'avatar' => $row['avatar'],
                    'display_name' => (string) $row['display_name'],
                ];
            });
            $manager->shouldReceive('for')->with($platform)->andReturn($adapter);
        }

        $suggestions = $this->makeService(app(FirecrawlClient::class), app(NanoGptClient::class), $manager)
            ->suggest($brand);

        $this->assertGreaterThanOrEqual(6, count($suggestions));
        $this->assertLessThanOrEqual(16, count($suggestions));

        $platforms = array_unique(array_column($suggestions, 'platform'));
        $this->assertGreaterThanOrEqual(2, count($platforms));

        $handles = array_map(strtolower(...), array_column($suggestions, 'handle'));
        $this->assertNotContains('grantgunner', $handles);
        $this->assertNotContains('grantgunner_official', $handles);
        $this->assertNotContains('grantgunner_local', $handles);

        foreach ($suggestions as $row) {
            $this->assertNotSame('', (string) $row['display_name']);
            $this->assertNotNull($row['avatar']);
            $this->assertDoesNotMatchRegularExpression('/(_local|tips)$/i', (string) $row['handle']);
        }

        $this->assertSame(
            array_map(
                fn (array $row): string => "{$row['platform']}:{$row['handle']}",
                $fixture['suggestions'],
            ),
            array_map(
                fn (array $row): string => "{$row['platform']}:{$row['handle']}",
                $suggestions,
            ),
        );
    }
}
