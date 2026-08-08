<?php

namespace App\Services\Analysis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NanoGptClient
{
    public function chat(array $messages, ?string $model = null, array $options = []): array
    {
        $model ??= (string) config('snitch.video_analysis.model');

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? config('snitch.video_analysis.temperature', 0.2),
            'max_tokens' => $options['max_tokens'] ?? config('snitch.video_analysis.max_tokens', 1800),
        ];

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        $response = $this->http()->post('/chat/completions', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('NanoGPT request failed: '.$response->body());
        }

        return $response->json();
    }

    public function extractAssistantText(array $response): string
    {
        $content = data_get($response, 'choices.0.message.content');

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                } elseif (is_array($part) && isset($part['text'])) {
                    $parts[] = (string) $part['text'];
                }
            }

            return trim(implode("\n", $parts));
        }

        return trim((string) $content);
    }

    protected function http(): PendingRequest
    {
        $apiKey = (string) config('snitch.nanogpt.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('NANOGPT_API_KEY is not configured.');
        }

        return Http::baseUrl((string) config('snitch.nanogpt.base_url'))
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('snitch.nanogpt.timeout', 180))
            ->retry(2, 1000, fn (mixed $exception): bool => $exception instanceof ConnectionException);
    }
}
