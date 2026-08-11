<?php

namespace Tests\Unit\Services\Music;

use App\Services\Music\SpotifyLinkResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpotifyLinkResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'snitch.music_recognition.spotify_resolver.enabled' => true,
            'snitch.music_recognition.spotify_resolver.cache_ttl_seconds' => 60,
            'snitch.music_recognition.spotify_resolver.search_limit' => 3,
            'snitch.firecrawl.api_key' => 'test-firecrawl',
            'snitch.firecrawl.base_url' => 'https://firecrawl.test',
        ]);
    }

    public function test_returns_direct_spotify_fields_without_calling_firecrawl(): void
    {
        Http::preventStrayRequests();

        $resolved = app(SpotifyLinkResolver::class)->resolve([
            'title' => 'Blinding Lights',
            'artist' => 'The Weeknd',
            'spotify_track_id' => '0VjIjW4GlUZAMYd2vXMi3b',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame('0VjIjW4GlUZAMYd2vXMi3b', $resolved['spotify_track_id']);
        $this->assertSame(
            'https://open.spotify.com/embed/track/0VjIjW4GlUZAMYd2vXMi3b',
            $resolved['spotify_embed_url'],
        );
        $this->assertSame('audd', $resolved['resolved_via']);
    }

    public function test_extracts_track_id_from_spotify_url_field(): void
    {
        Http::preventStrayRequests();

        $resolved = app(SpotifyLinkResolver::class)->resolve([
            'title' => 'x',
            'spotify_url' => 'https://open.spotify.com/track/1S8DHfSs4uzjrfM4EIlbCu?si=abc',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame('1S8DHfSs4uzjrfM4EIlbCu', $resolved['spotify_track_id']);
    }

    public function test_returns_null_when_disabled(): void
    {
        config(['snitch.music_recognition.spotify_resolver.enabled' => false]);
        Http::preventStrayRequests();

        $this->assertNull(app(SpotifyLinkResolver::class)->resolve([
            'title' => 'Blinding Lights',
            'artist' => 'The Weeknd',
        ]));
    }

    public function test_skips_firecrawl_for_original_audio(): void
    {
        Http::preventStrayRequests();

        $this->assertNull(app(SpotifyLinkResolver::class)->resolve([
            'title' => 'original sound - user123',
            'artist' => 'user123',
        ]));

        $this->assertNull(app(SpotifyLinkResolver::class)->resolve([
            'title' => 'Any title',
            'artist' => 'any',
            'is_original_audio' => true,
        ]));
    }

    public function test_resolves_via_firecrawl_and_caches_result(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'https://firecrawl.test/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'url' => 'https://spotifyanchor-web.app.link/foo',
                        'title' => 'Anchor',
                    ],
                    [
                        'url' => 'https://open.spotify.com/track/1S8DHfSs4uzjrfM4EIlbCu?si=abc',
                        'title' => 'Ordinary - Alex Warren',
                    ],
                ],
            ]),
        ]);

        $resolver = app(SpotifyLinkResolver::class);

        $resolved = $resolver->resolve([
            'title' => 'Ordinary',
            'artist' => 'Alex Warren',
            'isrc' => 'USATO2432117',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame('1S8DHfSs4uzjrfM4EIlbCu', $resolved['spotify_track_id']);
        $this->assertSame('firecrawl', $resolved['resolved_via']);

        $firstCallCount = count(Http::recorded());
        $this->assertGreaterThanOrEqual(1, $firstCallCount);

        $again = $resolver->resolve([
            'title' => 'Ordinary',
            'artist' => 'Alex Warren',
            'isrc' => 'USATO2432117',
        ]);

        $this->assertNotNull($again);
        $this->assertSame('1S8DHfSs4uzjrfM4EIlbCu', $again['spotify_track_id']);

        // Cached hit must not issue any additional HTTP calls.
        $this->assertSame($firstCallCount, count(Http::recorded()));
    }

    public function test_caches_negative_miss_to_prevent_repeat_lookups(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'https://firecrawl.test/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'url' => 'https://example.com/not-spotify',
                        'title' => 'Not Spotify',
                    ],
                ],
            ]),
        ]);

        $resolver = app(SpotifyLinkResolver::class);

        $this->assertNull($resolver->resolve([
            'title' => 'Some Obscure Track',
            'artist' => 'Nobody',
        ]));

        $firstCallCount = count(Http::recorded());
        $this->assertGreaterThanOrEqual(1, $firstCallCount);

        $this->assertNull($resolver->resolve([
            'title' => 'Some Obscure Track',
            'artist' => 'Nobody',
        ]));

        $this->assertSame($firstCallCount, count(Http::recorded()));
    }
}
