<?php

namespace Tests\Unit\Services\Music;

use App\Services\Music\AcoustIdClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AcoustIdClientTest extends TestCase
{
    public function test_returns_best_recording_hit_from_lookup(): void
    {
        config([
            'snitch.music_recognition.acoustid.api_key' => 'test-key',
            'snitch.music_recognition.acoustid.base_url' => 'https://api.acoustid.test/v2',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.acoustid.test/v2/lookup' => Http::response([
                'status' => 'ok',
                'results' => [
                    [
                        'id' => 'acid-1',
                        'score' => 0.92,
                        'recordings' => [
                            [
                                'id' => 'rec-1',
                                'title' => 'Sometimes',
                                'artists' => [
                                    ['id' => 'artist-1', 'name' => 'Fleetwood Mac'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'acid-2',
                        'score' => 0.65,
                        'recordings' => [
                            ['id' => 'rec-old', 'title' => 'Old Match'],
                        ],
                    ],
                ],
            ]),
        ]);

        $client = app(AcoustIdClient::class);

        $result = $client->lookup('AAAA-fingerprint', 214);

        $this->assertNotNull($result);
        $this->assertSame('acoustid', $result['provider']);
        $this->assertSame('Sometimes', $result['title']);
        $this->assertSame('Fleetwood Mac', $result['artist']);
        $this->assertSame(0.92, $result['confidence']);
        $this->assertSame('rec-1', $result['recording_id']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.acoustid.test/v2/lookup'
            && $request->data()['fingerprint'] === 'AAAA-fingerprint'
            && (int) $request->data()['duration'] === 214);
    }

    public function test_lookup_returns_null_when_api_key_missing(): void
    {
        config([
            'snitch.music_recognition.acoustid.api_key' => '',
        ]);

        Http::preventStrayRequests();

        $client = app(AcoustIdClient::class);

        $this->assertNull($client->lookup('anything', 30));
    }

    public function test_lookup_returns_null_when_no_recordings_match(): void
    {
        config([
            'snitch.music_recognition.acoustid.api_key' => 'test-key',
            'snitch.music_recognition.acoustid.base_url' => 'https://api.acoustid.test/v2',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.acoustid.test/v2/lookup' => Http::response([
                'status' => 'ok',
                'results' => [],
            ]),
        ]);

        $this->assertNull(app(AcoustIdClient::class)->lookup('fingerprint', 60));
    }

    public function test_lookup_returns_null_on_upstream_error(): void
    {
        config([
            'snitch.music_recognition.acoustid.api_key' => 'test-key',
            'snitch.music_recognition.acoustid.base_url' => 'https://api.acoustid.test/v2',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.acoustid.test/v2/lookup' => Http::response('nope', 500),
        ]);

        $this->assertNull(app(AcoustIdClient::class)->lookup('fingerprint', 60));
    }
}
