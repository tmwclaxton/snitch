<?php

namespace App\Services\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;
use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
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
        private AnalysisTermCatalogue $catalogue,
    ) {}

    public function analyzeUrl(string $mediaUrl, string $mediaKind = 'video', ?string $caption = null): VideoAnalysisResult
    {
        $model = (string) config('snitch.video_analysis.model');
        $prompt = $this->buildPrompt($mediaKind, $caption);

        $content = [
            ['type' => 'text', 'text' => $prompt],
            [
                'type' => 'video_url',
                'video_url' => ['url' => $mediaUrl],
            ],
        ];

        $response = $this->client->chat(
            messages: [
                [
                    'role' => 'system',
                    'content' => <<<'SYSTEM'
You analyze short-form social videos for creators who will remake the craft, not quote the script.
Return ONLY valid JSON matching the schema.
Write every string value in English (US), including concept, idea, topics, how_to_copy, visual_summary, cta, and labels.
Do not use Chinese or other non-English prose. Spoken-word quotes in hook may keep the original language, but all explanation stays English.
Prioritize reusable craft concepts and engagement mechanics.
Never dump or paraphrase long stretches of spoken script or caption.
Never invent music or SFX that are not audible in the media.
Reject vague filler ("engaging", "relatable vibe", "great energy") - name the mechanic.
Prefer catalogue slugs for hook_type_slugs, topic_slugs, and visual_craft_slugs. Use custom_tags only when nothing fits.
SYSTEM,
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

            if (! $post->type instanceof PostType || ! $post->type->isReelLike()) {
                throw new RuntimeException('Post type is not reel/video; analysis skipped.');
            }

            $result = $this->analyzeUrl($mediaUrl, 'video', $post->caption);
            $evaluation = $this->evaluator->evaluate($result, $post->caption);

            if (! $evaluation['passed']) {
                throw new RuntimeException('Analysis failed checklist: '.implode(', ', $evaluation['failures']));
            }

            $termIds = $this->resolveTermIds($result);
            $catalogueLabels = array_values(array_unique(array_merge(
                $this->catalogue->resolveLabels(AnalysisTermDimension::HookType, $result->hookTypeSlugs),
                $this->catalogue->resolveLabels(AnalysisTermDimension::Topic, $result->topicSlugs),
                $this->catalogue->resolveLabels(AnalysisTermDimension::VisualCraft, $result->visualCraftSlugs),
            )));
            $topics = array_values(array_unique(array_merge($result->topics, $catalogueLabels, $result->customTags)));

            $analysis->fill([
                'status' => AnalysisStatus::Completed,
                'hook' => $result->hook,
                'hook_window_end_sec' => (int) max(3, ceil($result->hookWindowEndSeconds)),
                'visual_summary' => $result->visualSummary,
                'idea' => $result->idea,
                'concept' => $result->concept,
                'topics' => $topics,
                'custom_tags' => $result->customTags,
                'format_notes' => null,
                'sfx' => $result->sfx,
                'music' => array_filter([
                    'title' => $result->musicTitle,
                    'artist' => $result->musicArtist,
                    'is_original_audio' => $result->isOriginalAudio,
                ], fn ($value) => $value !== null),
                'cta' => $result->cta,
                'how_to_copy' => $result->howToCopy,
                'model' => $result->model,
                'analyzed_at' => now(),
                'error_message' => null,
            ]);
            $analysis->save();
            $analysis->terms()->sync($termIds);

            return $analysis->refresh()->load('terms');
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

    /**
     * @return list<int>
     */
    private function resolveTermIds(VideoAnalysisResult $result): array
    {
        return array_values(array_unique(array_merge(
            $this->catalogue->resolveIds(AnalysisTermDimension::HookType, $result->hookTypeSlugs),
            $this->catalogue->resolveIds(AnalysisTermDimension::Topic, $result->topicSlugs),
            $this->catalogue->resolveIds(AnalysisTermDimension::VisualCraft, $result->visualCraftSlugs),
        )));
    }

    private function buildPrompt(string $mediaKind, ?string $caption): string
    {
        $captionLine = $caption ? "Caption (context only, do not paraphrase as the analysis): {$caption}" : 'Caption: (none)';
        $catalogueBlock = $this->catalogue->promptBlock();

        return <<<PROMPT
Analyze this {$mediaKind} short-form social post. Focus on craft concepts a creator can reuse.
{$captionLine}

Rules:
- Language = English only for all JSON string values (concept, idea, topics, how_to_copy, visual_summary, cta, sfx labels, custom_tags). No Chinese or mixed-language prose.
- Core concept = the reusable game/pattern in one crisp sentence (not a caption summary).
- Why it engages = name the mechanism (curiosity gap, proof, contrast, status, humor beat, etc.).
- Hook = scroll-stop device + timing; quote spoken words only if the quote IS the device.
- Visual cues = craft choices (composition, text-on-screen, cuts, framing).
- Music/SFX = role in the concept when present; empty array / null when none. Do not invent.
- Topics = short craft/theme labels in English (formats, niche memes, cultural hooks), not keyword stuffing.
- Taxonomy = pick catalogue slugs when they fit. Prefer 1 hook_type_slug, 1-3 topic_slugs, 1-3 visual_craft_slugs.
- custom_tags = short freeform labels ONLY when the catalogue misses something important.
- how_to_copy = 2-4 actionable remake steps for another brand applying the SAME concept (required, never empty).
- Do NOT dump the transcript or rewrite the caption.
- Keep hook/concept/idea short and specific; name the mechanic.

{$catalogueBlock}

Return JSON with keys:
{
  "concept": "string (reusable craft pattern)",
  "hook": "string",
  "hook_window": {"start_sec": 0, "end_sec": 3},
  "visual_summary": "string (craft-focused, detailed)",
  "idea": "string (why it engages / mechanism)",
  "topics": ["string"],
  "hook_type_slugs": ["pattern_interrupt"],
  "topic_slugs": ["grant_writing"],
  "visual_craft_slugs": ["talking_head"],
  "custom_tags": [],
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
