<?php

namespace App\Services\Blog;

use App\Services\Analysis\NanoGptClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NanoGptBlogHeroImageService
{
    /**
     * Generate a hero image from a text prompt via NanoGPT and store it on the configured disk.
     *
     * @return string|null Storage path (e.g. blogs/heroes/uuid.png), or null on failure.
     */
    public function generateAndStore(string $prompt): ?string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return null;
        }

        $apiKey = (string) config('snitch.nanogpt.api_key');
        if ($apiKey === '') {
            Log::warning('NanoGptBlogHeroImageService: missing NANOGPT_API_KEY; skipping hero image.');

            return null;
        }

        $baseUrl = rtrim((string) config('blog.image.base_url'), '/');

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('blog.image.timeout', 120))
                ->connectTimeout(30)
                ->post("{$baseUrl}/images/generations", [
                    'model' => config('blog.image.model'),
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => config('blog.image.size'),
                    'response_format' => 'url',
                ]);

            if (! $response->successful()) {
                Log::warning('NanoGptBlogHeroImageService: image API request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $binary = $this->binaryFromResponse($response->json());
            if ($binary === null || $binary === '') {
                Log::warning('NanoGptBlogHeroImageService: empty image bytes from API.');

                return null;
            }

            return $this->storePngAndReturnPath($binary);
        } catch (Throwable $e) {
            Log::warning('NanoGptBlogHeroImageService: unexpected error.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array{slug?: string, title?: string, tags?: list<string>}  $context
     */
    public function buildPrompt(NanoGptClient $nanoGpt, string $topic, array $context = []): string
    {
        $fallback = $this->fallbackPrompt($topic);

        try {
            $tags = implode(', ', $context['tags'] ?? []);
            $decoded = $nanoGpt->chatJson(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
You write image prompts for Flux Schnell used as wide 16:9 blog heroes.
Return JSON only: {"prompt":"..."}.
Style: soft risograph print, warm cream paper, charcoal + mustard / fluorescent yellow spot inks, halftone dots, ink grain, misregistration, flat ink fills, paper show-through, zine / editorial poster.
Subject: abstract or scene metaphor for competitor social tracking - contact sheets, binoculars, scrapboards, polaroids - not photoreal people faces, no neon purple, no coral primary, no glossy 3D, no baked wordmarks or gibberish UI text.
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => "Topic: {$topic}\nTags: {$tags}\nWrite one vivid scene prompt.",
                    ],
                ],
                (string) config('blog.generate.model'),
                ['temperature' => 0.7, 'max_tokens' => 400],
            );

            $prompt = is_array($decoded) ? trim((string) ($decoded['prompt'] ?? '')) : '';

            return $prompt !== '' ? $prompt : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    protected function fallbackPrompt(string $topic): string
    {
        return 'Soft risograph print illustration on warm cream paper, charcoal and mustard yellow spot inks, '
            .'halftone dots and ink grain, slight misregistration, zine editorial poster, '
            .'detective contact sheet and binoculars metaphor for competitor social tracking, '
            .'flat ink fills, no neon purple, no photoreal faces, no text or wordmarks. Topic mood: '
            .Str::limit($topic, 120, '');
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function binaryFromResponse(?array $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $first = $payload['data'][0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        if (isset($first['url']) && is_string($first['url']) && $first['url'] !== '') {
            $download = Http::timeout(120)->get($first['url']);
            if (! $download->successful()) {
                return null;
            }

            return $download->body();
        }

        if (isset($first['b64_json']) && is_string($first['b64_json']) && $first['b64_json'] !== '') {
            $decoded = base64_decode($first['b64_json'], true);

            return $decoded === false ? null : $decoded;
        }

        return null;
    }

    protected function storePngAndReturnPath(string $binary): ?string
    {
        $diskName = (string) config('blog.hero_image_disk', 'public');
        $prefix = (string) config('blog.hero_image_path_prefix', 'blogs/heroes');
        $normalizedPrefix = $prefix !== '' ? trim($prefix, '/').'/' : '';
        $objectPath = $normalizedPrefix.Str::uuid().'.png';

        try {
            if (! Storage::disk($diskName)->put($objectPath, $binary, ['visibility' => 'public'])) {
                throw new RuntimeException('Storage put returned false.');
            }
        } catch (Throwable $e) {
            Log::warning('NanoGptBlogHeroImageService: storage failed.', [
                'disk' => $diskName,
                'path' => $objectPath,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return $objectPath;
    }
}
