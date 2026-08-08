<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\NanoGptClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NanoGptClientTest extends TestCase
{
    public function test_chat_retries_once_on_connection_exception(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'secret-nano-token',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.nanogpt.timeout' => 5,
            'snitch.video_analysis.model' => 'test-model',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://nano-gpt.test/api/v1/*' => Http::sequence()
                ->pushFailedConnection('cURL error 28: Operation timed out')
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => '{"ok":true}',
                            ],
                        ],
                    ],
                ]),
        ]);

        $response = app(NanoGptClient::class)->chat([
            ['role' => 'user', 'content' => 'ping'],
        ]);

        Http::assertSentCount(2);
        $this->assertSame('{"ok":true}', data_get($response, 'choices.0.message.content'));
    }
}
