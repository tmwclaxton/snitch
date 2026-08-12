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
            'max_tokens' => $options['max_tokens'] ?? config('snitch.video_analysis.max_tokens', 8192),
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

    /**
     * OpenAI-compatible finish_reason (e.g. stop, length).
     */
    public function extractFinishReason(array $response): ?string
    {
        $reason = data_get($response, 'choices.0.finish_reason');

        if (! is_string($reason)) {
            return null;
        }

        $trimmed = trim($reason);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * OpenAI-compatible usage block (prompt/completion or input/output aliases).
     *
     * @param  array<string, mixed>  $response
     * @return array{prompt_tokens: int|null, completion_tokens: int|null}
     */
    public function extractUsage(array $response): array
    {
        $usage = $response['usage'] ?? null;

        if (! is_array($usage)) {
            return [
                'prompt_tokens' => null,
                'completion_tokens' => null,
            ];
        }

        $prompt = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $completion = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;

        return [
            'prompt_tokens' => is_numeric($prompt) ? (int) $prompt : null,
            'completion_tokens' => is_numeric($completion) ? (int) $completion : null,
        ];
    }

    /**
     * Chat expecting a JSON object; returns decoded array or null.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    public function chatJson(array $messages, ?string $model = null, array $options = []): ?array
    {
        $options['response_format'] = $options['response_format'] ?? ['type' => 'json_object'];

        $text = $this->extractAssistantText($this->chat($messages, $model, $options));

        if ($text === '') {
            return null;
        }

        if (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            $text = $matches[0];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Create embedding vectors for one or more input strings.
     *
     * @param  string|list<string>  $input
     * @return list<list<float>>
     */
    public function embeddings(string|array $input, ?string $model = null, ?int $dimensions = null): array
    {
        $model ??= (string) config('snitch.embeddings.model', 'text-embedding-3-small');
        $dimensions ??= (int) config('snitch.embeddings.dimensions', 0);

        $payload = [
            'model' => $model,
            'input' => $input,
        ];

        if ($dimensions > 0) {
            $payload['dimensions'] = $dimensions;
        }

        $response = $this->http()->post('/embeddings', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('NanoGPT embeddings request failed: '.$response->body());
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new RuntimeException('NanoGPT embeddings response missing data.');
        }

        $vectors = [];

        foreach ($data as $row) {
            $embedding = is_array($row) ? ($row['embedding'] ?? null) : null;

            if (! is_array($embedding) || $embedding === []) {
                throw new RuntimeException('NanoGPT embeddings response contained an empty vector.');
            }

            $vectors[] = array_map(static fn (mixed $value): float => (float) $value, $embedding);
        }

        return $vectors;
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
