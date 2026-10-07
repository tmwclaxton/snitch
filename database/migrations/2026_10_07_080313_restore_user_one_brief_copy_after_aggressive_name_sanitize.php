<?php

use App\Models\DailyBrief;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $weekStart = CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);

        $this->restoreUserOneWeeklyIdeas($weekStart);
        $this->repairUserOneDailyBriefs($weekStart);
        $this->flagOtherUsersWithCorruptedMemberCopy($weekStart);
    }

    public function down(): void
    {
        // One-way repair.
    }

    private function restoreUserOneWeeklyIdeas(CarbonImmutable $weekStart): void
    {
        if (User::query()->find(1) === null) {
            return;
        }

        $brief = WeeklyBrief::query()
            ->where('user_id', 1)
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        if ($brief === null) {
            return;
        }

        $restored = [
            1 => [
                'hook' => 'POV: you found a free social club',
                'caption_angle' => "Show how Let's Go Social makes it easy to meet new people with zero pressure.",
            ],
            2 => [
                'hook' => "A member's story: nervous, alone, now a regular",
                'caption_angle' => 'Highlight how welcoming the group is for first-timers, using a real member testimonial.',
            ],
            3 => [
                'hook' => 'We hiked 10 miles and then got a pint',
                'caption_angle' => "Show the balance of activity and socialising that defines Let's Go Social events.",
            ],
        ];

        foreach ($restored as $position => $fields) {
            $idea = WeeklyBriefIdea::query()
                ->where('weekly_brief_id', $brief->id)
                ->where('position', $position)
                ->first();

            if ($idea === null) {
                continue;
            }

            $idea->fill($fields);

            foreach (['visual', 'cta', 'why'] as $field) {
                $value = $idea->{$field};
                if (is_string($value) && $this->looksLikeCorruptedMemberCopy($value)) {
                    $idea->{$field} = null;
                }
            }

            $idea->save();
        }
    }

    private function repairUserOneDailyBriefs(CarbonImmutable $weekStart): void
    {
        $brief = WeeklyBrief::query()
            ->where('user_id', 1)
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        $ideas = $brief === null
            ? []
            : WeeklyBriefIdea::query()
                ->where('weekly_brief_id', $brief->id)
                ->whereNull('used_at')
                ->orderBy('position')
                ->get()
                ->map(fn (WeeklyBriefIdea $idea): array => [
                    'position' => (int) $idea->position,
                    'format' => $idea->format,
                    'hook' => $idea->hook,
                    'caption_angle' => $idea->caption_angle,
                ])
                ->all();

        $briefs = DailyBrief::query()
            ->where('user_id', 1)
            ->where('status', 'ready')
            ->orderByDesc('brief_date')
            ->get();

        foreach ($briefs as $daily) {
            $payload = is_array($daily->payload) ? $daily->payload : [];
            $payload['unused_weekly_ideas'] = $ideas;

            $headline = (string) ($daily->headline ?? $payload['headline'] ?? '');
            $corrupted = $this->looksLikeCorruptedMemberCopy($headline)
                || $this->payloadHasCorruptedMemberCopy($payload);

            if ($corrupted) {
                $payload['headline'] = 'Regenerate today\'s summary for a clean draft.';
                $payload['own_summary'] = '';
                $payload['competitor_summary'] = '';
                $payload['actions'] = [];
                $payload['watch'] = ['Regenerate today\'s summary - previous copy was corrupted by a name sanitizer bug.'];
                $payload['validation'] = [
                    'used_fallback' => true,
                    'diagnostics' => [[
                        'attempt' => 0,
                        'errors' => ['restored after aggressive borrowed-name sanitizer'],
                    ]],
                ];
                $daily->headline = $payload['headline'];
            }

            $daily->payload = $payload;
            $daily->save();
        }
    }

    /**
     * Prior migrations only touched user 1. Warn if any other ready rows still
     * look corrupted so ops can regenerate them.
     */
    private function flagOtherUsersWithCorruptedMemberCopy(CarbonImmutable $weekStart): void
    {
        $weeklyBriefIds = WeeklyBrief::query()
            ->where('user_id', '!=', 1)
            ->whereDate('week_start', $weekStart->toDateString())
            ->pluck('id');

        $weeklyHits = $weeklyBriefIds->isEmpty()
            ? 0
            : WeeklyBriefIdea::query()
                ->whereIn('weekly_brief_id', $weeklyBriefIds)
                ->where(function ($query): void {
                    foreach (['hook', 'visual', 'caption_angle', 'cta', 'why'] as $field) {
                        $query->orWhere($field, 'like', '%a member%');
                    }
                })
                ->count();

        $dailyHits = DailyBrief::query()
            ->where('user_id', '!=', 1)
            ->where('status', 'ready')
            ->where(function ($query): void {
                $query->where('headline', 'like', '%a member%')
                    ->orWhere('payload', 'like', '%a member%');
            })
            ->count();

        if ($weeklyHits > 0 || $dailyHits > 0) {
            Log::warning('Borrowed-name sanitizer corruption found outside user 1', [
                'weekly_idea_rows' => $weeklyHits,
                'daily_briefs' => $dailyHits,
                'week_start' => $weekStart->toDateString(),
            ]);
        }
    }

    private function looksLikeCorruptedMemberCopy(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (preg_match('/\b(?:a member(?:\'s)?\s+){2,}/i', $text) === 1) {
            return true;
        }

        return substr_count(mb_strtolower($text), 'a member') >= 2;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHasCorruptedMemberCopy(array $payload): bool
    {
        $blob = json_encode([
            $payload['headline'] ?? '',
            $payload['own_summary'] ?? '',
            $payload['competitor_summary'] ?? '',
            $payload['actions'] ?? [],
            $payload['watch'] ?? [],
        ], JSON_UNESCAPED_UNICODE) ?: '';

        return $this->looksLikeCorruptedMemberCopy($blob);
    }
};
