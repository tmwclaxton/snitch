<?php

namespace App\Services\Brief;

use App\Models\DailyBrief;
use App\Models\User;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ExecutiveBriefCurator
{
    public function __construct(
        private DailyBriefGenerator $generator,
        private ExecutiveBriefPresenter $presenter,
    ) {}

    /**
     * Override summary and/or suggestions on a ready daily brief for a user.
     *
     * @param  array{summary?: string|null, suggestions?: list<array{title: string, why: string, related_post_ids?: list<int>}>|null}  $input
     * @return array<string, mixed>
     */
    public function setForUser(User $subject, User $actor, array $input, ?CarbonImmutable $date = null): array
    {
        $date ??= $this->generator->briefDate();
        $brief = $this->generator->briefForDate($subject, $date);

        if ($brief === null || $brief->status !== 'ready') {
            $brief = DailyBrief::query()
                ->where('user_id', $subject->id)
                ->where('status', 'ready')
                ->orderByDesc('brief_date')
                ->first();
        }

        if ($brief === null) {
            throw ValidationException::withMessages([
                'brief' => ['No ready Executive Brief exists for this user yet.'],
            ]);
        }

        $hasSummary = array_key_exists('summary', $input);
        $hasSuggestions = array_key_exists('suggestions', $input);

        if (! $hasSummary && ! $hasSuggestions) {
            throw ValidationException::withMessages([
                'summary' => ['Provide summary and/or suggestions to override.'],
            ]);
        }

        $payload = is_array($brief->payload) ? $brief->payload : [];
        $overrides = is_array($payload['curator_overrides'] ?? null)
            ? $payload['curator_overrides']
            : [];

        if ($hasSummary) {
            $summary = $input['summary'];
            if ($summary === null) {
                unset($overrides['summary']);
            } else {
                $summary = trim((string) $summary);
                if ($summary === '' || mb_strlen($summary) > 2000) {
                    throw ValidationException::withMessages([
                        'summary' => ['Summary must be 1 to 2000 characters, or null to clear.'],
                    ]);
                }
                if (str_contains($summary, '—') || str_contains($summary, '–')) {
                    throw ValidationException::withMessages([
                        'summary' => ['Summary must not use em or en dashes.'],
                    ]);
                }
                $overrides['summary'] = $summary;
            }
        }

        if ($hasSuggestions) {
            $suggestions = $input['suggestions'];
            if ($suggestions === null) {
                unset($overrides['suggestions']);
            } else {
                if (! is_array($suggestions) || $suggestions === []) {
                    throw ValidationException::withMessages([
                        'suggestions' => ['Suggestions must be a non-empty list, or null to clear.'],
                    ]);
                }
                if (count($suggestions) > 5) {
                    throw ValidationException::withMessages([
                        'suggestions' => ['Provide at most 5 suggestions.'],
                    ]);
                }

                $normalised = [];
                foreach (array_values($suggestions) as $index => $row) {
                    if (! is_array($row)) {
                        throw ValidationException::withMessages([
                            "suggestions.$index" => ['Each suggestion must be an object.'],
                        ]);
                    }
                    $title = trim((string) ($row['title'] ?? ''));
                    $why = trim((string) ($row['why'] ?? ''));
                    if ($title === '' || $why === '') {
                        throw ValidationException::withMessages([
                            "suggestions.$index" => ['Each suggestion needs title and why.'],
                        ]);
                    }
                    if (str_contains($title, '—') || str_contains($title, '–')
                        || str_contains($why, '—') || str_contains($why, '–')) {
                        throw ValidationException::withMessages([
                            "suggestions.$index" => ['Suggestions must not use em or en dashes.'],
                        ]);
                    }
                    $ids = [];
                    foreach ($row['related_post_ids'] ?? [] as $id) {
                        $ids[] = (int) $id;
                    }
                    $normalised[] = [
                        'title' => $title,
                        'why' => $why,
                        'related_post_ids' => array_values(array_unique(array_filter($ids))),
                    ];
                }
                $overrides['suggestions'] = $normalised;
            }
        }

        $overrides['updated_at'] = CarbonImmutable::now(DashboardMath::TIMEZONE)->toIso8601String();
        $overrides['updated_by_user_id'] = (int) $actor->id;

        $payload['curator_overrides'] = $overrides;
        if (isset($overrides['summary'])) {
            $payload['summary'] = $overrides['summary'];
        }

        $brief->payload = $payload;
        $brief->save();

        return $this->presenter->fromBrief($brief->fresh() ?? $brief);
    }
}
