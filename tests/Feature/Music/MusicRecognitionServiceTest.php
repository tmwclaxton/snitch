<?php

namespace Tests\Feature\Music;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Music\AcoustIdClient;
use App\Services\Music\AudDClient;
use App\Services\Music\AudioClipExtractor;
use App\Services\Music\ChromaprintFingerprinter;
use App\Services\Music\MusicRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class MusicRecognitionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snitch.music_recognition.enabled' => true,
            'snitch.music_recognition.min_confidence' => 0.55,
            'snitch.music_recognition.silence_dbfs' => -45.0,
            'snitch.music_recognition.acoustid.api_key' => 'test-acoustid',
            'snitch.music_recognition.audd.api_key' => 'test-audd',
            'snitch.music_recognition.spotify_resolver.enabled' => false,
            'snitch.firecrawl.api_key' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_platform_music_wins_and_skips_providers(): void
    {
        $social = SocialAccount::factory()->create(['platform' => 'tiktok']);
        $post = Post::factory()->forSocialAccount($social)->create([
            'raw_payload' => [
                'normalized_music' => [
                    'musicName' => 'Late night feelings',
                    'musicAuthor' => 'Mylesxiety',
                    'musicOriginal' => false,
                    'musicId' => '7123',
                ],
            ],
        ]);

        $clipExtractor = Mockery::mock(AudioClipExtractor::class);
        $clipExtractor->shouldNotReceive('extractFromMediaUrl');

        $fingerprinter = Mockery::mock(ChromaprintFingerprinter::class);
        $acoustId = Mockery::mock(AcoustIdClient::class);
        $audd = Mockery::mock(AudDClient::class);

        $this->app->instance(AudioClipExtractor::class, $clipExtractor);
        $this->app->instance(ChromaprintFingerprinter::class, $fingerprinter);
        $this->app->instance(AcoustIdClient::class, $acoustId);
        $this->app->instance(AudDClient::class, $audd);

        $result = app(MusicRecognitionService::class)->recognize($post);

        $this->assertNotNull($result);
        $this->assertSame('platform', $result['source']);
        $this->assertSame('Late night feelings', $result['title']);
        $this->assertSame('Mylesxiety', $result['artist']);
        $this->assertFalse($result['is_original_audio']);
    }

    public function test_falls_through_to_acoustid_when_platform_missing(): void
    {
        $post = Post::factory()->create([
            'media_url' => 'https://cdn.example.com/reel.mp4',
            'raw_payload' => null,
        ]);

        $clipPath = $this->writeStubClip('acoustid-hit-');

        $clipExtractor = Mockery::mock(AudioClipExtractor::class);
        $clipExtractor->shouldReceive('extractFromMediaUrl')
            ->once()
            ->andReturn([
                'path' => $clipPath,
                'format' => 'mp3',
                'seconds' => 12,
                'mean_dbfs' => -18.5,
                'sha256' => 'hash-acoustid-1',
            ]);
        $clipExtractor->shouldReceive('cleanup')->once();

        $fingerprinter = Mockery::mock(ChromaprintFingerprinter::class);
        $fingerprinter->shouldReceive('fingerprint')
            ->once()
            ->with($clipPath)
            ->andReturn(['fingerprint' => 'AAAA', 'duration' => 200]);

        $acoustId = Mockery::mock(AcoustIdClient::class);
        $acoustId->shouldReceive('isConfigured')->andReturn(true);
        $acoustId->shouldReceive('lookup')
            ->once()
            ->with('AAAA', 200)
            ->andReturn([
                'provider' => 'acoustid',
                'title' => 'Never Gonna Give You Up',
                'artist' => 'Rick Astley',
                'confidence' => 0.9,
                'recording_id' => 'mb-rec-1',
                'raw' => null,
            ]);

        $audd = Mockery::mock(AudDClient::class);
        $audd->shouldNotReceive('recognizeFile');

        $this->app->instance(AudioClipExtractor::class, $clipExtractor);
        $this->app->instance(ChromaprintFingerprinter::class, $fingerprinter);
        $this->app->instance(AcoustIdClient::class, $acoustId);
        $this->app->instance(AudDClient::class, $audd);

        try {
            $result = app(MusicRecognitionService::class)->recognize($post);
        } finally {
            @unlink($clipPath);
        }

        $this->assertNotNull($result);
        $this->assertSame('acoustid', $result['source']);
        $this->assertSame('acoustid', $result['provider']);
        $this->assertSame('Never Gonna Give You Up', $result['title']);
        $this->assertSame('Rick Astley', $result['artist']);
        $this->assertSame(0.9, $result['confidence']);
        $this->assertFalse($result['is_original_audio']);
        $this->assertSame('mb-rec-1', $result['recording_id']);
        $this->assertSame('hash-acoustid-1', $result['media_hash']);
    }

    public function test_falls_back_to_audd_when_acoustid_returns_null(): void
    {
        $post = Post::factory()->create([
            'media_url' => 'https://cdn.example.com/reel.mp4',
            'raw_payload' => null,
        ]);

        $clipPath = $this->writeStubClip('audd-hit-');

        $clipExtractor = Mockery::mock(AudioClipExtractor::class);
        $clipExtractor->shouldReceive('extractFromMediaUrl')
            ->once()
            ->andReturn([
                'path' => $clipPath,
                'format' => 'mp3',
                'seconds' => 12,
                'mean_dbfs' => -22.0,
                'sha256' => 'hash-audd-1',
            ]);
        $clipExtractor->shouldReceive('cleanup')->once();

        $fingerprinter = Mockery::mock(ChromaprintFingerprinter::class);
        $fingerprinter->shouldReceive('fingerprint')->andReturn(null);

        $acoustId = Mockery::mock(AcoustIdClient::class);
        $acoustId->shouldReceive('isConfigured')->andReturn(true);
        $acoustId->shouldNotReceive('lookup');

        $audd = Mockery::mock(AudDClient::class);
        $audd->shouldReceive('isConfigured')->andReturn(true);
        $audd->shouldReceive('recognizeFile')
            ->once()
            ->andReturn([
                'provider' => 'audd',
                'title' => 'Ordinary',
                'artist' => 'Alex Warren',
                'album' => 'You\'ll Be Alright',
                'isrc' => 'USATO2432117',
                'confidence' => 0.9,
                'spotify_track_id' => '1S8DHfSs4uzjrfM4EIlbCu',
                'spotify_url' => 'https://open.spotify.com/track/1S8DHfSs4uzjrfM4EIlbCu',
                'apple_music_id' => null,
                'apple_music_url' => null,
                'raw' => ['spotify_id' => '1S8DHfSs4uzjrfM4EIlbCu'],
            ]);

        $this->app->instance(AudioClipExtractor::class, $clipExtractor);
        $this->app->instance(ChromaprintFingerprinter::class, $fingerprinter);
        $this->app->instance(AcoustIdClient::class, $acoustId);
        $this->app->instance(AudDClient::class, $audd);

        try {
            $result = app(MusicRecognitionService::class)->recognize($post);
        } finally {
            @unlink($clipPath);
        }

        $this->assertNotNull($result);
        $this->assertSame('audd', $result['source']);
        $this->assertSame('Ordinary', $result['title']);
        $this->assertSame('Alex Warren', $result['artist']);
        $this->assertSame('USATO2432117', $result['isrc']);
        $this->assertSame('1S8DHfSs4uzjrfM4EIlbCu', $result['spotify_track_id']);
        $this->assertSame('https://open.spotify.com/track/1S8DHfSs4uzjrfM4EIlbCu', $result['spotify_url']);
        $this->assertSame(
            'https://open.spotify.com/embed/track/1S8DHfSs4uzjrfM4EIlbCu',
            $result['spotify_embed_url'],
        );
        $this->assertSame('audd', $result['spotify_resolved_via']);
    }

    public function test_skips_silent_clips_without_calling_providers(): void
    {
        $post = Post::factory()->create([
            'media_url' => 'https://cdn.example.com/silent.mp4',
            'raw_payload' => null,
        ]);

        $clipPath = $this->writeStubClip('silent-');

        $clipExtractor = Mockery::mock(AudioClipExtractor::class);
        $clipExtractor->shouldReceive('extractFromMediaUrl')
            ->once()
            ->andReturn([
                'path' => $clipPath,
                'format' => 'mp3',
                'seconds' => 12,
                'mean_dbfs' => -70.0,
                'sha256' => 'hash-silent',
            ]);
        $clipExtractor->shouldReceive('cleanup')->once();

        $fingerprinter = Mockery::mock(ChromaprintFingerprinter::class);
        $fingerprinter->shouldNotReceive('fingerprint');

        $acoustId = Mockery::mock(AcoustIdClient::class);
        $acoustId->shouldNotReceive('lookup');

        $audd = Mockery::mock(AudDClient::class);
        $audd->shouldNotReceive('recognizeFile');

        $this->app->instance(AudioClipExtractor::class, $clipExtractor);
        $this->app->instance(ChromaprintFingerprinter::class, $fingerprinter);
        $this->app->instance(AcoustIdClient::class, $acoustId);
        $this->app->instance(AudDClient::class, $audd);

        try {
            $this->assertNull(app(MusicRecognitionService::class)->recognize($post));
        } finally {
            @unlink($clipPath);
        }
    }

    public function test_recognition_caches_by_media_hash(): void
    {
        Cache::flush();

        $post = Post::factory()->create([
            'media_url' => 'https://cdn.example.com/rickroll.mp4',
            'raw_payload' => null,
        ]);

        $clipPath = $this->writeStubClip('cache-first-');

        $clipExtractor = Mockery::mock(AudioClipExtractor::class);
        $clipExtractor->shouldReceive('extractFromMediaUrl')
            ->twice()
            ->andReturnUsing(function () use ($clipPath) {
                if (! is_file($clipPath)) {
                    file_put_contents($clipPath, 'clip');
                }

                return [
                    'path' => $clipPath,
                    'format' => 'mp3',
                    'seconds' => 12,
                    'mean_dbfs' => -20.0,
                    'sha256' => 'hash-cached-1',
                ];
            });
        $clipExtractor->shouldReceive('cleanup')->twice();

        $fingerprinter = Mockery::mock(ChromaprintFingerprinter::class);
        $fingerprinter->shouldReceive('fingerprint')
            ->once()
            ->andReturn(['fingerprint' => 'FP', 'duration' => 90]);

        $acoustId = Mockery::mock(AcoustIdClient::class);
        $acoustId->shouldReceive('isConfigured')->andReturn(true);
        $acoustId->shouldReceive('lookup')
            ->once()
            ->andReturn([
                'provider' => 'acoustid',
                'title' => 'Cached Song',
                'artist' => 'Cached Artist',
                'confidence' => 0.9,
                'recording_id' => 'rec-cache',
                'raw' => null,
            ]);

        $audd = Mockery::mock(AudDClient::class);
        $audd->shouldReceive('isConfigured')->andReturn(true);
        $audd->shouldNotReceive('recognizeFile');

        $this->app->instance(AudioClipExtractor::class, $clipExtractor);
        $this->app->instance(ChromaprintFingerprinter::class, $fingerprinter);
        $this->app->instance(AcoustIdClient::class, $acoustId);
        $this->app->instance(AudDClient::class, $audd);

        try {
            $first = app(MusicRecognitionService::class)->recognize($post);
            $second = app(MusicRecognitionService::class)->recognize($post);
        } finally {
            @unlink($clipPath);
        }

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first['title'], $second['title']);
        $this->assertSame('hash-cached-1', $second['media_hash']);
    }

    public function test_returns_null_when_no_provider_matches(): void
    {
        $post = Post::factory()->create([
            'media_url' => 'https://cdn.example.com/reel.mp4',
            'raw_payload' => null,
        ]);

        $clipPath = $this->writeStubClip('nomatch-');

        $clipExtractor = Mockery::mock(AudioClipExtractor::class);
        $clipExtractor->shouldReceive('extractFromMediaUrl')
            ->once()
            ->andReturn([
                'path' => $clipPath,
                'format' => 'mp3',
                'seconds' => 12,
                'mean_dbfs' => -25.0,
                'sha256' => 'hash-nomatch',
            ]);
        $clipExtractor->shouldReceive('cleanup')->once();

        $fingerprinter = Mockery::mock(ChromaprintFingerprinter::class);
        $fingerprinter->shouldReceive('fingerprint')
            ->andReturn(['fingerprint' => 'x', 'duration' => 50]);

        $acoustId = Mockery::mock(AcoustIdClient::class);
        $acoustId->shouldReceive('isConfigured')->andReturn(true);
        $acoustId->shouldReceive('lookup')->andReturn(null);

        $audd = Mockery::mock(AudDClient::class);
        $audd->shouldReceive('isConfigured')->andReturn(true);
        $audd->shouldReceive('recognizeFile')->andReturn(null);

        $this->app->instance(AudioClipExtractor::class, $clipExtractor);
        $this->app->instance(ChromaprintFingerprinter::class, $fingerprinter);
        $this->app->instance(AcoustIdClient::class, $acoustId);
        $this->app->instance(AudDClient::class, $audd);

        try {
            $this->assertNull(app(MusicRecognitionService::class)->recognize($post));
        } finally {
            @unlink($clipPath);
        }
    }

    private function writeStubClip(string $prefix): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertNotFalse($tmp, 'Failed to allocate temp clip file for test');

        file_put_contents($tmp, "clip-bytes-{$prefix}");

        return $tmp;
    }
}
