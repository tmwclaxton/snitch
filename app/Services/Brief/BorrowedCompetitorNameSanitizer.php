<?php

namespace App\Services\Brief;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;

class BorrowedCompetitorNameSanitizer
{
    /**
     * Straight or curly apostrophe used in possessives (Jessie's / Jessie’s).
     */
    private const APOSTROPHE = '[\'\x{2019}\x{2018}]';

    /**
     * Common capitalized words that are not people.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august',
        'september', 'october', 'november', 'december',
        'london', 'uk', 'england', 'britain', 'instagram', 'reel', 'reels', 'story',
        'stories', 'carousel', 'image', 'post', 'posts', 'community', 'member',
        'members', 'friend', 'friends', 'friendship', 'dinner', 'traitors', 'pov',
        'new', 'the', 'and', 'for', 'with', 'from', 'this', 'that', 'your', 'our',
        'free', 'join', 'meet', 'here', 'what', 'when', 'where', 'how', 'why',
        'great', 'good', 'best', 'first', 'last', 'next', 'today', 'tonight',
        'tomorrow', 'week', 'weekend', 'social', 'club', 'event', 'events',
        'ticket', 'tickets', 'link', 'bio', 'comment', 'comments', 'share', 'save',
        'dm', 'dms', 'hello', 'hi', 'hey', 'yes', 'no', 'ok', 'okay',
        'spotlight', 'regular', 'nervous', 'alone', 'now', 'was', 'were', 'been',
        'open', 'close', 'start', 'finish', 'after', 'before', 'about', 'into',
    ];

    /**
     * @param  list<string>  $ownCaptions
     * @param  list<string>  $competitorCaptions
     * @return list<string>
     */
    public function borrowedNames(array $ownCaptions, array $competitorCaptions): array
    {
        $own = [];
        foreach ($ownCaptions as $caption) {
            foreach ($this->extractPersonNames($caption) as $name) {
                $own[mb_strtolower($name)] = true;
            }
        }

        $borrowed = [];
        foreach ($competitorCaptions as $caption) {
            foreach ($this->extractPersonNames($caption) as $name) {
                $key = mb_strtolower($name);
                if (! isset($own[$key])) {
                    $borrowed[$key] = $this->canonicalName($name);
                }
            }
        }

        return array_values($borrowed);
    }

    /**
     * @return list<string>
     */
    public function borrowedNamesForUser(User $user): array
    {
        [$own, $competitors] = $this->captionsForUser($user);

        return $this->borrowedNames($own, $competitors);
    }

    /**
     * @param  list<string>  $borrowedNames
     */
    public function rewriteText(string $text, array $borrowedNames): string
    {
        if ($borrowedNames === [] || $text === '') {
            return $text;
        }

        $rewritten = $text;

        foreach ($borrowedNames as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $quoted = preg_quote($name, '/');
            $rewritten = preg_replace(
                '/\b'.$quoted.self::APOSTROPHE.'s\b/iu',
                "a member's",
                $rewritten,
            ) ?? $rewritten;
            $rewritten = preg_replace('/\b'.$quoted.'\b/iu', 'a member', $rewritten) ?? $rewritten;
        }

        $rewritten = preg_replace('/(^|[.!?]\s+)a member\'s/u', '$1A member\'s', $rewritten) ?? $rewritten;
        $rewritten = preg_replace('/(^|[.!?]\s+)a member\b/u', '$1A member', $rewritten) ?? $rewritten;

        return trim((string) preg_replace('/\s{2,}/u', ' ', $rewritten));
    }

    /**
     * @param  list<string>  $borrowedNames
     */
    public function firstBorrowedNameIn(string $text, array $borrowedNames): ?string
    {
        foreach ($borrowedNames as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            if (preg_match('/\b'.preg_quote($name, '/').'(?:'.self::APOSTROPHE.'s)?\b/iu', $text) === 1) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Edit stored weekly ideas for a user in place. Returns how many rows changed.
     *
     * @param  bool  $allWeeks  When true, clean every weekly brief for the user (not only the current week).
     */
    public function sanitizeStoredWeeklyIdeas(User $user, ?CarbonImmutable $weekStart = null, bool $allWeeks = false): int
    {
        $names = $this->borrowedNamesForUser($user);

        if ($names === []) {
            return 0;
        }

        if (! $allWeeks) {
            $weekStart ??= CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        }

        $briefs = WeeklyBrief::query()
            ->where('user_id', $user->id)
            ->when(
                ! $allWeeks && $weekStart !== null,
                fn ($query) => $query->whereDate('week_start', $weekStart->toDateString()),
            )
            ->orderBy('id')
            ->get();

        if ($briefs->isEmpty()) {
            return 0;
        }

        $updated = 0;

        foreach ($briefs as $brief) {
            foreach (WeeklyBriefIdea::query()->where('weekly_brief_id', $brief->id)->orderBy('id')->get() as $idea) {
                $dirty = false;
                $fields = [
                    'hook' => $idea->hook,
                    'visual' => $idea->visual,
                    'caption_angle' => $idea->caption_angle,
                    'cta' => $idea->cta,
                    'why' => $idea->why,
                ];

                foreach ($fields as $key => $value) {
                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    $rewritten = $this->rewriteText($value, $names);
                    if ($rewritten !== $value) {
                        $fields[$key] = $rewritten;
                        $dirty = true;
                    }
                }

                if (! $dirty) {
                    continue;
                }

                $idea->fill($fields);
                $idea->save();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @return list<string>
     */
    public function extractPersonNames(string $caption): array
    {
        if (trim($caption) === '') {
            return [];
        }

        preg_match_all('/\b([A-Z][a-z]{2,})(?:'.self::APOSTROPHE.'s)?\b/u', $caption, $matches);

        $names = [];

        foreach ($matches[1] ?? [] as $raw) {
            $name = $this->canonicalName((string) $raw);
            $key = mb_strtolower($name);

            if (strlen($key) < 3 || in_array($key, self::STOPWORDS, true)) {
                continue;
            }

            $names[$key] = $name;
        }

        return array_values($names);
    }

    private function canonicalName(string $name): string
    {
        $trimmed = trim($name);

        return mb_strtoupper(mb_substr($trimmed, 0, 1)).mb_strtolower(mb_substr($trimmed, 1));
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function captionsForUser(User $user): array
    {
        $accounts = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::Instagram)
            ->where(function ($query): void {
                $query->where('is_own_account', true)
                    ->orWhere('kind', TrackedAccountKind::Competitor);
            })
            ->get();

        $ownIds = $accounts->where('is_own_account', true)->pluck('social_account_id')->filter()->all();
        $compIds = $accounts->where('is_own_account', false)->pluck('social_account_id')->filter()->all();

        $own = $ownIds === []
            ? []
            : Post::query()->whereIn('social_account_id', $ownIds)->pluck('caption')->filter()->values()->all();
        $competitors = $compIds === []
            ? []
            : Post::query()->whereIn('social_account_id', $compIds)->pluck('caption')->filter()->values()->all();

        return [
            array_map(fn ($caption): string => (string) $caption, $own),
            array_map(fn ($caption): string => (string) $caption, $competitors),
        ];
    }
}
