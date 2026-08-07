<?php

namespace App\Services\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;
use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Models\Post;
use App\Models\PostAnalysis;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VideoAnalysisService
{
    public function __construct(
        private NanoGptClient $client,
        private VideoAnalysisSuccessEvaluator $evaluator,
    ) {}

    public function analyzeUrl(string $mediaUrl, string $mediaKind = 'video', ?string $caption = null): VideoAnalysisResult
    {
        $model = (string) config('snitch.video_analysis.model');
        $prompt = $this->buildPrompt($mediaKind, $caption);

        $content = [
            ['type' => 'text', 'text' => $prompt],
        ];

        if ($mediaKind === 'image') {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $mediaUrl],
            ];
        } else {
            $content[] = [
                'type' => 'video_url',
                'video_url' => ['url' => $mediaUrl],
            ];
        }

        $response = $this->client->chat(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You analyze social posts. Return ONLY valid JSON matching the schema.',
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            model: $model,
            options: [
                'response_format' => ['type' => 'json_object'],
            ],
        );

        $text = $this->client->extractAssistantText($response);
        $payload = json_decode($this->extractJson($text), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Video analysis did not return valid JSON.');
        }

        return VideoAnalysisResult::fromModelPayload($payload, $model);
    }

    public function analyzePost(Post $post): PostAnalysis
    {
        $analysis = PostAnalysis::query()->firstOrNew(['post_id' => $post->id]);
        $analysis->fill([
            'status' => AnalysisStatus::Processing,
            'error_message' => null,
        ]);
        $analysis->save();

        try {
            $mediaUrl = (string) $post->media_url;

            if ($mediaUrl === '') {
                throw new RuntimeException('Post has no media_url to analyze.');
            }

            $mediaKind = in_array($post->type, [PostType::Image, PostType::Carousel], true)
                ? 'image'
                : 'video';

            $result = $this->analyzeUrl($mediaUrl, $mediaKind, $post->caption);
            $evaluation = $this->evaluator->evaluate($result);

            if (! $evaluation['passed']) {
                throw new RuntimeException('Analysis failed checklist: '.implode(', ', $evaluation['failures']));
            }

            $analysis->fill([
                'status' => AnalysisStatus::Completed,
                'hook' => $result->hook,
                'hook_window_end_sec' => (int) max(3, ceil($result->hookWindowEndSeconds)),
                'visual_summary' => $result->visualSummary,
                'idea' => $result->idea,
                'format_notes' => null,
                'sfx' => $result->sfx,
                'music' => array_filter([
                    'title' => $result->musicTitle,
                    'artist' => $result->musicArtist,
                    'is_original_audio' => $result->isOriginalAudio,
                ], fn ($value) => $value !== null),
                'cta' => $result->cta,
                'model' => $result->model,
                'analyzed_at' => now(),
                'error_message' => null,
            ]);
            $analysis->save();

            return $analysis->refresh();
        } catch (Throwable $e) {
            Log::warning('Post analysis failed', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            $analysis->fill([
                'status' => AnalysisStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            $analysis->save();

            throw $e;
        }
    }

    private function buildPrompt(string $mediaKind, ?string $caption): string
    {
        $captionLine = $caption ? "Caption: {$caption}" : 'Caption: (none)';

        return <<<PROMPT
Analyze this {$mediaKind} social post. Focus the hook on the first ~3 seconds.
{$captionLine}

Return JSON with keys:
{
  "hook": "string",
  "hook_window": {"start_sec": 0, "end_sec": 3},
  "visual_summary": "string (detailed)",
  "idea": "string",
  "cta": "string",
  "how_to_copy": "string (actionable remake steps)",
  "sfx": [{"at_sec": 0.5, "label": "whoosh", "role": "transition"}],
  "music_title": "string|null",
  "music_artist": "string|null",
  "is_original_audio": false
}
PROMPT;
    }

    private function extractJson(string $text): string
    {
        if (str_starts_with(trim($text), '{')) {
            return trim($text);
        }

        if (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            return $matches[0];
        }

        return $text;
    }
}
