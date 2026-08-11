<?php

namespace Tests\Unit\Services\Music;

use App\Services\Music\AudDClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AudDClientTest extends TestCase
{
    public function test_recognize_file_returns_best_result(): void
    {
        config([
            'snitch.music_recognition.audd.api_key' => 'test-audd',
            'snitch.music_recognition.audd.base_url' => 'https://audd.test',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://audd.test/*' => Http::response([
                'status' => 'success',
                'result' => [
                    'title' => 'Blinding Lights',
                    'artist' => 'The Weeknd',
                    'album' => 'After Hours',
                    'isrc' => 'USUG11904206',
                    'song_link' => 'https://lis.tn/dummy',
                    'apple_music' => [
                        'id' => 'apple-123',
                        'isrc' => 'USUG11904206',
                        'url' => 'https://music.apple.com/us/album/apple-123',
                    ],
                    'spotify' => [
                        'id' => '0VjIjW4GlUZAMYd2vXMi3b',
                        'external_urls' => [
                            'spotify' => 'https://open.spotify.com/track/0VjIjW4GlUZAMYd2vXMi3b',
                        ],
                    ],
                ],
            ]),
        ]);

        $clip = $this->writeTempClip('audd-clip-');

        try {
            $result = app(AudDClient::class)->recognizeFile($clip);
        } finally {
            @unlink($clip);
        }

        $this->assertNotNull($result);
        $this->assertSame('audd', $result['provider']);
        $this->assertSame('Blinding Lights', $result['title']);
        $this->assertSame('The Weeknd', $result['artist']);
        $this->assertSame('USUG11904206', $result['isrc']);
        $this->assertGreaterThanOrEqual(0.85, $result['confidence']);
        $this->assertSame('0VjIjW4GlUZAMYd2vXMi3b', $result['spotify_track_id']);
        $this->assertSame('https://open.spotify.com/track/0VjIjW4GlUZAMYd2vXMi3b', $result['spotify_url']);
        $this->assertSame('apple-123', $result['apple_music_id']);
        $this->assertSame('https://music.apple.com/us/album/apple-123', $result['apple_music_url']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://audd.test');
        });
    }

    public function test_recognize_file_returns_null_when_no_key_configured(): void
    {
        config([
            'snitch.music_recognition.audd.api_key' => '',
        ]);

        Http::preventStrayRequests();

        $clip = $this->writeTempClip('audd-noop-');

        try {
            $this->assertNull(app(AudDClient::class)->recognizeFile($clip));
        } finally {
            @unlink($clip);
        }
    }

    public function test_recognize_file_returns_null_when_status_not_success(): void
    {
        config([
            'snitch.music_recognition.audd.api_key' => 'test-audd',
            'snitch.music_recognition.audd.base_url' => 'https://audd.test',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://audd.test/*' => Http::response([
                'status' => 'error',
                'error' => ['message' => 'quota exceeded'],
            ]),
        ]);

        $clip = $this->writeTempClip('audd-error-');

        try {
            $this->assertNull(app(AudDClient::class)->recognizeFile($clip));
        } finally {
            @unlink($clip);
        }
    }

    private function writeTempClip(string $prefix): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertNotFalse($tmp, 'Failed to allocate temp file for AudD test');

        file_put_contents($tmp, "clip-bytes-{$prefix}");

        return $tmp;
    }
}
