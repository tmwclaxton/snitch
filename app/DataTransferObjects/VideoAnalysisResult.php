<?php

namespace App\DataTransferObjects;

final readonly class VideoAnalysisResult
{
    /**
     * @param  array<int, array{at_sec: float|null, label: string, role: string|null}>  $sfx
     * @param  list<string>  $topics
     * @param  list<string>  $hookTypeSlugs
     * @param  list<string>  $topicSlugs
     * @param  list<string>  $visualCraftSlugs
     * @param  list<string>  $customTags
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $hook,
        public float $hookWindowStartSeconds,
        public float $hookWindowEndSeconds,
        public string $visualSummary,
        public string $idea,
        public string $concept,
        public string $cta,
        public string $howToCopy,
        public array $sfx,
        public array $topics,
        public array $hookTypeSlugs,
        public array $topicSlugs,
        public array $visualCraftSlugs,
        public array $customTags,
        public ?string $musicTitle,
        public ?string $musicArtist,
        public bool $isOriginalAudio,
        public string $model,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromModelPayload(
        array $payload,
        string $model,
        ?float $minHookWindowEndSeconds = null,
    ): self {
        $hookWindow = is_array($payload['hook_window'] ?? null) ? $payload['hook_window'] : [];
        $sfxItems = [];

        foreach ($payload['sfx'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? $item['name'] ?? ''));

            if ($label === '') {
                continue;
            }

            $sfxItems[] = [
                'at_sec' => isset($item['at_sec']) ? (float) $item['at_sec'] : (isset($item['timestamp']) ? (float) $item['timestamp'] : null),
                'label' => $label,
                'role' => isset($item['role']) ? trim((string) $item['role']) : null,
            ];
        }

        $topics = self::stringList($payload['topics'] ?? $payload['themes'] ?? []);
        $hookTypeSlugs = self::slugList($payload['hook_type_slugs'] ?? []);
        $topicSlugs = self::slugList($payload['topic_slugs'] ?? []);
        $visualCraftSlugs = self::slugList($payload['visual_craft_slugs'] ?? []);
        $customTags = self::stringList($payload['custom_tags'] ?? []);

        $concept = trim((string) ($payload['concept'] ?? $payload['core_concept'] ?? ''));
        if ($concept === '') {
            $concept = trim((string) ($payload['idea'] ?? ''));
        }

        // Models often return 0-2.5s for short opens; floor to the same minimum we persist.
        $minHookEnd = $minHookWindowEndSeconds ?? 3.0;
        $hookWindowEnd = max($minHookEnd, (float) ($hookWindow['end_sec'] ?? 0));

        // Prompt historically allowed empty CTA when no ask; evaluator requires a value.
        // Floor empty CTA so real "no ask" posts do not burn AnalyzePostJob retries.
        $cta = trim((string) ($payload['cta'] ?? ''));
        if ($cta === '') {
            $cta = 'No explicit CTA';
        }

        return new self(
            hook: trim((string) ($payload['hook'] ?? '')),
            hookWindowStartSeconds: (float) ($hookWindow['start_sec'] ?? 0),
            hookWindowEndSeconds: $hookWindowEnd,
            visualSummary: trim((string) ($payload['visual_summary'] ?? '')),
            idea: trim((string) ($payload['idea'] ?? '')),
            concept: $concept,
            cta: $cta,
            howToCopy: trim((string) ($payload['how_to_copy'] ?? '')),
            sfx: $sfxItems,
            topics: $topics,
            hookTypeSlugs: $hookTypeSlugs,
            topicSlugs: $topicSlugs,
            visualCraftSlugs: $visualCraftSlugs,
            customTags: $customTags,
            musicTitle: self::nullableString($payload['music_title'] ?? null),
            musicArtist: self::nullableString($payload['music_artist'] ?? null),
            isOriginalAudio: (bool) ($payload['is_original_audio'] ?? false),
            model: $model,
            raw: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hook' => $this->hook,
            'hook_window' => [
                'start_sec' => $this->hookWindowStartSeconds,
                'end_sec' => $this->hookWindowEndSeconds,
            ],
            'visual_summary' => $this->visualSummary,
            'idea' => $this->idea,
            'concept' => $this->concept,
            'cta' => $this->cta,
            'how_to_copy' => $this->howToCopy,
            'topics' => $this->topics,
            'hook_type_slugs' => $this->hookTypeSlugs,
            'topic_slugs' => $this->topicSlugs,
            'visual_craft_slugs' => $this->visualCraftSlugs,
            'custom_tags' => $this->customTags,
            'sfx' => $this->sfx,
            'music_title' => $this->musicTitle,
            'music_artist' => $this->musicArtist,
            'is_original_audio' => $this->isOriginalAudio,
            'model' => $this->model,
        ];
    }

    public function hasTaxonomySignal(): bool
    {
        return $this->hookTypeSlugs !== []
            || $this->topicSlugs !== []
            || $this->visualCraftSlugs !== []
            || $this->customTags !== [];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $items[] = $trimmed;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @return list<string>
     */
    private static function slugList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $trimmed = strtolower(trim((string) $item));
            $trimmed = preg_replace('/[^a-z0-9_\-]/', '', $trimmed) ?? '';
            if ($trimmed !== '') {
                $items[] = $trimmed;
            }
        }

        return array_values(array_unique($items));
    }
}
