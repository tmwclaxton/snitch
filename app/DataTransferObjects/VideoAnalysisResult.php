<?php

namespace App\DataTransferObjects;

final readonly class VideoAnalysisResult
{
    /**
     * @param  array<int, array{at_sec: float|null, label: string, role: string|null}>  $sfx
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $hook,
        public float $hookWindowStartSeconds,
        public float $hookWindowEndSeconds,
        public string $visualSummary,
        public string $idea,
        public string $cta,
        public string $howToCopy,
        public array $sfx,
        public ?string $musicTitle,
        public ?string $musicArtist,
        public bool $isOriginalAudio,
        public string $model,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromModelPayload(array $payload, string $model): self
    {
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

        return new self(
            hook: trim((string) ($payload['hook'] ?? '')),
            hookWindowStartSeconds: (float) ($hookWindow['start_sec'] ?? 0),
            hookWindowEndSeconds: (float) ($hookWindow['end_sec'] ?? 0),
            visualSummary: trim((string) ($payload['visual_summary'] ?? '')),
            idea: trim((string) ($payload['idea'] ?? '')),
            cta: trim((string) ($payload['cta'] ?? '')),
            howToCopy: trim((string) ($payload['how_to_copy'] ?? '')),
            sfx: $sfxItems,
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
            'cta' => $this->cta,
            'how_to_copy' => $this->howToCopy,
            'sfx' => $this->sfx,
            'music_title' => $this->musicTitle,
            'music_artist' => $this->musicArtist,
            'is_original_audio' => $this->isOriginalAudio,
            'model' => $this->model,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
