<?php

namespace App\Services\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;
use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
use App\Enums\PostType;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Services\Music\MusicRecognitionService;
use App\Services\SnitchAnalyticsService;
use App\Support\PublicDiskMedia;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VideoAnalysisService
{
    public function __construct(
        private NanoGptClient $client,
        private VideoAnalysisSuccessEvaluator $evaluator,
        private AnalysisTermCatalogue $catalogue,
        private AnalysisTermInferrer $inferrer,
        private PlatformMusicExtractor $musicExtractor,
        private MusicRecognitionService $musicRecognition,
        private SnitchAnalyticsService $analytics,
    ) {}

    /**
     * @param  array<string, mixed>|null  $platformMusic
     */
    public function analyzeUrl(
        string $mediaUrl,
        string $mediaKind = 'video',
        ?string $caption = null,
        ?array $platformMusic = null,
    ): VideoAnalysisResult {
        $model = (string) config('snitch.video_analysis.model');
        $prompt = $this->buildPrompt($mediaKind, $caption, $platformMusic);

        $content = [
            ['type' => 'text', 'text' => $prompt],
            [
                'type' => 'video_url',
                'video_url' => ['url' => $mediaUrl],
            ],
        ];

        $maxTokens = (int) config('snitch.video_analysis.max_tokens', 16384);

        $response = $this->client->chat(
            messages: [
                [
                    'role' => 'system',
                    'content' => <<<'SYSTEM'
You analyse short-form social videos for creators who will remake the craft, not quote the script.
Return ONLY valid JSON matching the schema.
Write every string value in English (UK), including concept, idea, topics, how_to_copy, visual_summary, cta, and labels.
Do not use Chinese or other non-English prose. Spoken-word quotes in hook may keep the original language, but all explanation stays English.
Prioritise reusable craft concepts and engagement mechanics.
Keep the concept-first fields (hook / concept / idea / visual_summary / how_to_copy) concise and free of long transcript dumps or caption paraphrasing - put every verbatim spoken word in the separate transcript field instead, from first word to last, without summarizing or truncating.
When speech is present, reserve most of your output token budget for transcript until the reel ends. Do not stop early.
Never invent music or SFX that are not audible in the media. Prefer platform music metadata when provided over guessing a song title.
Reject vague filler ("engaging", "relatable vibe", "great energy") - name the mechanic.
Always fill hook_type_slugs, topic_slugs, and visual_craft_slugs from the controlled catalogue when they fit (e.g. myth_bust for myth-busting opens). Use custom_tags only when nothing fits.
When you see real VFX (particles, glitch/VHS, greenscreen keying, sticker packs, motion graphics, screen warp, light leaks, CapCut template FX, AI face filters), emit the matching Grade & effects visual_craft slugs. Do not call ordinary jump cuts, fades, or colour grade "VFX".
For how_to_copy, always use a Markdown numbered list with a real newline before each step (1. / 2. / 3.). Never write steps inline on one line. Keep cta as the post's ask only - not inside how_to_copy.
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
                'max_tokens' => $maxTokens,
            ],
        );

        $text = $this->client->extractAssistantText($response);
        $usage = $this->client->extractUsage($response);
        $finishReason = $this->client->extractFinishReason($response);
        $payload = json_decode($this->extractJson($text), true);

        if (! is_array($payload)) {
            Log::warning('Video analysis response was not valid JSON.', [
                'finish_reason' => $finishReason,
                'prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
                'assistant_text_snippet' => $this->logSnippet($text),
                'json_error' => json_last_error_msg(),
            ]);

            throw new RuntimeException('Video analysis did not return valid JSON.');
        }

        if ($finishReason === 'length') {
            Log::warning('Video analysis response hit max_tokens; transcript may be incomplete.', [
                'max_tokens' => $maxTokens,
                'completion_tokens' => $usage['completion_tokens'],
                'model' => $model,
            ]);
        }

        return VideoAnalysisResult::fromModelPayload(
            $payload,
            $model,
            (float) config('snitch.video_analysis.success.min_hook_window_end_seconds'),
            $usage['prompt_tokens'],
            $usage['completion_tokens'],
            outputTruncated: $finishReason === 'length',
        );
    }

    /**
     * @return array{analysis: PostAnalysis, prompt_tokens: int|null, completion_tokens: int|null}
     */
    public function analyzePost(Post $post): array
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

            $recognizedMusic = $this->resolveAuthoritativeMusic($post);

            // Local APP_URL storage links are not fetchable by NanoGPT; inline bytes.
            $result = $this->analyzeUrl(
                PublicDiskMedia::analyzableUrl($mediaUrl),
                'video',
                $post->caption,
                $recognizedMusic,
            );
            $evaluation = $this->evaluator->evaluate($result, $post->caption);

            if (! $evaluation['passed']) {
                throw new RuntimeException('Analysis failed checklist: '.implode(', ', $evaluation['failures']));
            }

            // Models often write freeform "myth-busting" topics but omit catalogue slugs;
            // infer missing taxonomy so Explore filters stay useful.
            $inferredSlugs = $this->inferrer->inferSlugs([
                'hook' => $result->hook,
                'concept' => $result->concept,
                'idea' => $result->idea,
                'visual_summary' => $result->visualSummary,
                'topics' => $result->topics,
                'custom_tags' => $result->customTags,
            ]);
            $hookTypeSlugs = array_values(array_unique(array_merge($result->hookTypeSlugs, $inferredSlugs['hook_type'])));
            $topicSlugs = array_values(array_unique(array_merge($result->topicSlugs, $inferredSlugs['topic'])));
            $visualCraftSlugs = array_values(array_unique(array_merge($result->visualCraftSlugs, $inferredSlugs['visual_craft'])));
            $termIds = array_values(array_unique(array_merge(
                $this->catalogue->resolveIds(AnalysisTermDimension::HookType, $hookTypeSlugs),
                $this->catalogue->resolveIds(AnalysisTermDimension::Topic, $topicSlugs),
                $this->catalogue->resolveIds(AnalysisTermDimension::VisualCraft, $visualCraftSlugs),
            )));
            $catalogueLabels = array_values(array_unique(array_merge(
                $this->catalogue->resolveLabels(AnalysisTermDimension::HookType, $hookTypeSlugs),
                $this->catalogue->resolveLabels(AnalysisTermDimension::Topic, $topicSlugs),
                $this->catalogue->resolveLabels(AnalysisTermDimension::VisualCraft, $visualCraftSlugs),
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
                'music' => $this->musicExtractor->mergeForAnalysis(
                    $recognizedMusic,
                    $result->musicTitle,
                    $result->musicArtist,
                    $result->isOriginalAudio,
                ),
                'cta' => $result->cta,
                'how_to_copy' => $result->howToCopy,
                'transcript' => $this->persistableTranscript($result),
                'model' => $result->model,
                'analyzed_at' => now(),
                'error_message' => null,
            ]);
            $analysis->save();
            $analysis->terms()->sync($termIds);
            $this->analytics->recordAnalysisCompleted();

            return [
                'analysis' => $analysis->refresh()->load('terms'),
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
            ];
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
     * Prefer platform music metadata; when absent, fall back to AcoustID +
     * chromaprint and then AudD via MusicRecognitionService. Failures are
     * swallowed so recognition never blocks a working analysis.
     *
     * @return array<string, mixed>|null
     */
    private function resolveAuthoritativeMusic(Post $post): ?array
    {
        try {
            return $this->musicRecognition->recognize($post);
        } catch (Throwable $e) {
            Log::info('Music recognition failed; continuing without provider song id.', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            return $this->musicExtractor->fromPost($post);
        }
    }

    /**
     * @param  array<string, mixed>|null  $platformMusic
     */
    private function buildPrompt(string $mediaKind, ?string $caption, ?array $platformMusic = null): string
    {
        $captionLine = $caption ? "Caption (context only, do not paraphrase as the analysis): {$caption}" : 'Caption: (none)';
        $catalogueBlock = $this->catalogue->promptBlock();
        $musicLine = $this->platformMusicLine($platformMusic);

        return <<<PROMPT
Analyse this {$mediaKind} short-form social post. Focus on craft concepts a creator can reuse.
{$captionLine}
{$musicLine}

Rules:
- Language = English (UK) only for all JSON string values (concept, idea, topics, how_to_copy, visual_summary, cta, sfx labels, custom_tags). No Chinese or mixed-language prose.
- Core concept = the reusable game/pattern in one crisp sentence (not a caption summary).
- Why it engages = name the mechanism (curiosity gap, proof, contrast, status, humor beat, etc.).
- Hook = scroll-stop device + timing; quote spoken words only if the quote IS the device.
- Visual cues = craft choices (composition, text-on-screen, cuts, framing, grade, VFX). Name specific effects when present.
- Music/SFX = role in the concept when present; empty array / null when none. Do not invent song titles. When platform music metadata is provided above, copy that title/artist/original flag into music_* fields (or leave null) - never invent a conflicting track name.
- Topics = short craft/theme labels in English (formats, niche memes, cultural hooks), not keyword stuffing.
- Taxonomy = ALWAYS pick catalogue slugs when they fit (required for filters). Prefer 1 hook_type_slug, 1-3 topic_slugs, 1-4 visual_craft_slugs. If topics mention myth-busting / pattern interrupt / etc., emit the matching slug.
- Visual craft: include Grade & effects slugs for real VFX (particle_fx, vhs_glitch, greenscreen, sticker_pack_overlay, emoji_bursts, motion_graphics, screen_distort, light_leak_flare, object_tracking, ai_face_filter, capcut_template_fx, film_grain, duotone_grade, neon_accent). Do NOT tag ordinary jump cuts, crossfades, mild colour correction, or platform chrome as VFX. Prefer those slugs over a freeform "vfx" custom_tag.
- custom_tags = short freeform labels ONLY when the catalogue misses something important.
- how_to_copy = 2-4 actionable remake steps for another brand applying the SAME concept (required, never empty). Put each step on its own line as a Markdown numbered list (e.g. "1. ...\\n2. ...\\n3. ..."). Never pack steps onto one line. Do not bury the post CTA inside how_to_copy - CTA has its own field.
- cta = the post's ask / next action only (separate from remake steps). Always fill this string; use "No explicit CTA" when the post has no ask.
- transcript = highest priority when speech is present. Capture every verbatim spoken word from the first syllable to the last, in original language, as one plain string. Line breaks between sentences or speakers are fine; phrase-per-line is fine. Include the full script even when long (60-90s talking heads often need thousands of tokens). Do NOT paraphrase, summarize, truncate, translate, or add commentary. Never stop mid-sentence or mid-reel. Empty string "" only when silent or purely music/SFX. The transcript field is separate from the concept-first fields above and never counts as caption echo.
- Keep hook, concept, idea, visual_summary, and how_to_copy tight so transcript can use the remaining output budget.
- Do NOT reuse the transcript inside hook/concept/idea/visual_summary/how_to_copy - the transcript field is where spoken words live.
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
  "cta": "string (post ask only)",
  "how_to_copy": "1. First remake step\\n2. Second step\\n3. Third step",
  "transcript": "string (verbatim spoken words, original language, or empty when silent)",
  "sfx": [{"at_sec": 0.5, "label": "whoosh", "role": "transition"}],
  "music_title": "string|null",
  "music_artist": "string|null",
  "is_original_audio": false
}
PROMPT;
    }

    /**
     * @param  array<string, mixed>|null  $platformMusic
     */
    private function platformMusicLine(?array $platformMusic): string
    {
        if ($platformMusic === null) {
            return 'Music metadata: (none - do not invent a commercial track title)';
        }

        $title = $platformMusic['title'] ?? 'unknown';
        $artist = $platformMusic['artist'] ?? 'unknown';
        $original = array_key_exists('is_original_audio', $platformMusic) && $platformMusic['is_original_audio'] !== null
            ? ($platformMusic['is_original_audio'] ? 'yes' : 'no')
            : 'unknown';
        $id = $platformMusic['platform_id'] ?? ($platformMusic['recording_id'] ?? 'unknown');
        $source = $platformMusic['source'] ?? 'platform';
        $sourceLabel = match ($source) {
            'acoustid' => 'AcoustID fingerprint',
            'audd' => 'AudD recognition',
            default => 'platform metadata',
        };

        return "Music metadata (authoritative via {$sourceLabel}): title={$title}; artist={$artist}; original_audio={$original}; id={$id}";
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

    private function logSnippet(string $text, int $maxBytes = 500): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $normalized = preg_replace('/([?&]token=)[^&\s"\']+/i', '$1[redacted]', $normalized) ?? $normalized;
        $normalized = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-+=\/]+/i', '$1[redacted]', $normalized) ?? $normalized;
        $normalized = preg_replace('/(api[_-]?key=)[^&\s"\']+/i', '$1[redacted]', $normalized) ?? $normalized;

        if (strlen($normalized) <= $maxBytes) {
            return $normalized;
        }

        return substr($normalized, 0, $maxBytes).'...';
    }

    private function persistableTranscript(VideoAnalysisResult $result): ?string
    {
        $transcript = trim($result->transcript);

        if ($transcript === '') {
            return null;
        }

        if ($result->outputTruncated) {
            $transcript .= "\n\n[Output limit reached; transcript may be incomplete. Re-analyze to retry.]";
        }

        return $transcript;
    }
}
