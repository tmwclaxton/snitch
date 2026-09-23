<?php

namespace App\Services\Insights;

use App\Enums\PostType;
use App\Models\Post;
use App\Models\TrackedAccount;
use Illuminate\Support\Collection;

class CompetitorInsights
{
    /**
     * @var list<string>
     */
    private const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * @var list<string>
     */
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'if', 'then', 'so', 'to', 'of', 'in', 'on', 'at', 'by', 'for',
        'with', 'from', 'as', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do',
        'does', 'did', 'will', 'would', 'can', 'could', 'should', 'may', 'might', 'must', 'i', 'you', 'he',
        'she', 'it', 'we', 'they', 'me', 'him', 'her', 'us', 'them', 'my', 'your', 'his', 'its', 'our',
        'their', 'this', 'that', 'these', 'those', 'what', 'which', 'who', 'whom', 'there', 'here', 'when',
        'where', 'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more', 'most', 'some', 'such', 'no',
        'nor', 'not', 'only', 'own', 'same', 'than', 'too', 'very', 'just', 'up', 'down', 'out', 'over',
        'under', 'again', 'once', 'also', 'get', 'got', 'like', 'go', 'going', 'gone', 'make', 'made',
        'new', 'now', 'one', 'two', 'three', 'day', 'days', 'today', 'tomorrow', 'yesterday', 'im', 'ive',
        'dont', 'didnt', 'cant', 'wont', 'youre', 'theyre', 'amp',
    ];

    /**
     * @var array<string, string>
     */
    private const TRAIT_PHRASES = [
        'Asks a question' => 'asking a question',
        'Has CTA' => 'with a clear call to action',
        'Uses emoji' => 'using an emoji',
        'Uses hashtags' => 'using a hashtag',
        'Tags an account' => 'tagging an account',
    ];

    /**
     * @var array<string, string>
     */
    private const GAP_PATTERN_PHRASES = [
        'Asks a question' => 'asked a question',
        'Uses emoji' => 'used an emoji',
        'Has CTA' => 'included a call to action',
        'Uses hashtags' => 'used a hashtag',
        'Tags an account' => 'tagged an account',
    ];

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Post>  $posts
     * @return array{
     *     bestTimes: list<array{day: string, hour: int, er: float, n: int}>,
     *     formatStats: list<array{format: string, er: float, n: int}>,
     *     bestLength: array{label: string, er: float, n: int}|null,
     *     patternLifts: list<array{label: string, withEr: float, withoutEr: float, lift: float, n: int}>,
     *     hasAnyData: bool
     * }
     */
    public function compute(Collection $accounts, Collection $posts): array
    {
        $followersById = $this->followersByAccountId($accounts);
        $erBuckets = [];

        for ($day = 0; $day < 7; $day++) {
            $erBuckets[$day] = array_fill(0, 24, ['sum' => 0.0, 'n' => 0]);
        }

        $formatAgg = [];
        $captionPosts = [];

        foreach ($posts as $post) {
            $er = $this->engagementRate($post, $followersById);

            if ($er <= 0) {
                continue;
            }

            if ($post->posted_at !== null) {
                $dayIndex = ((int) $post->posted_at->dayOfWeekIso) - 1;
                $hour = (int) $post->posted_at->hour;
                $erBuckets[$dayIndex][$hour]['sum'] += $er;
                $erBuckets[$dayIndex][$hour]['n']++;
            }

            $format = $this->formatLabel($post->type);
            $formatAgg[$format] ??= ['sum' => 0.0, 'n' => 0];
            $formatAgg[$format]['sum'] += $er;
            $formatAgg[$format]['n']++;

            $captionPosts[] = [
                'caption' => trim((string) $post->caption),
                'er' => $er,
            ];
        }

        $bestTimes = [];

        foreach ($erBuckets as $dayIndex => $hours) {
            foreach ($hours as $hour => $bucket) {
                if ($bucket['n'] === 0) {
                    continue;
                }

                $bestTimes[] = [
                    'day' => self::DAYS[$dayIndex],
                    'hour' => $hour,
                    'er' => $bucket['sum'] / $bucket['n'],
                    'n' => $bucket['n'],
                ];
            }
        }

        usort($bestTimes, fn (array $a, array $b): int => $b['er'] <=> $a['er']);
        $bestTimes = array_slice($bestTimes, 0, 3);

        $formatStats = [];

        foreach ($formatAgg as $format => $bucket) {
            $formatStats[] = [
                'format' => $format,
                'er' => $bucket['sum'] / $bucket['n'],
                'n' => $bucket['n'],
            ];
        }

        usort($formatStats, fn (array $a, array $b): int => $b['er'] <=> $a['er']);

        $lengthDefs = [
            ['label' => '<50', 'test' => fn (int $n): bool => $n < 50],
            ['label' => '50-150', 'test' => fn (int $n): bool => $n >= 50 && $n < 150],
            ['label' => '150-300', 'test' => fn (int $n): bool => $n >= 150 && $n < 300],
            ['label' => '300+', 'test' => fn (int $n): bool => $n >= 300],
        ];

        $lengthBuckets = [];

        foreach ($lengthDefs as $def) {
            $matches = array_values(array_filter(
                $captionPosts,
                fn (array $row): bool => $def['test'](mb_strlen($row['caption'])),
            ));
            $n = count($matches);
            $er = $n > 0 ? array_sum(array_column($matches, 'er')) / $n : 0.0;
            $lengthBuckets[] = ['label' => $def['label'], 'er' => $er, 'n' => $n];
        }

        $qualified = array_values(array_filter($lengthBuckets, fn (array $b): bool => $b['n'] >= 2));
        usort($qualified, fn (array $a, array $b): int => $b['er'] <=> $a['er']);
        $bestLength = $qualified[0] ?? null;

        $patternLifts = [];

        foreach ($this->patternDefs() as $def) {
            $withIt = array_values(array_filter($captionPosts, fn (array $row): bool => $def['test']($row['caption'])));
            $without = array_values(array_filter($captionPosts, fn (array $row): bool => ! $def['test']($row['caption'])));

            if (count($withIt) < 2 || count($without) < 2) {
                continue;
            }

            $withEr = array_sum(array_column($withIt, 'er')) / count($withIt);
            $withoutEr = array_sum(array_column($without, 'er')) / count($without);
            $lift = $withoutEr > 0 ? $withEr / $withoutEr : 0.0;

            $patternLifts[] = [
                'label' => $def['label'],
                'withEr' => $withEr,
                'withoutEr' => $withoutEr,
                'lift' => $lift,
                'n' => count($withIt),
            ];
        }

        usort($patternLifts, fn (array $a, array $b): int => $b['lift'] <=> $a['lift']);
        $patternLifts = array_slice($patternLifts, 0, 3);

        return [
            'bestTimes' => $bestTimes,
            'formatStats' => $formatStats,
            'bestLength' => $bestLength,
            'patternLifts' => $patternLifts,
            'hasAnyData' => $bestTimes !== [] || $formatStats !== [] || $bestLength !== null || $patternLifts !== [],
        ];
    }

    /**
     * @param  array{
     *     bestTimes: list<array{day: string, hour: int, er: float, n: int}>,
     *     formatStats: list<array{format: string, er: float, n: int}>,
     *     bestLength: array{label: string, er: float, n: int}|null,
     *     patternLifts: list<array{label: string, withEr: float, withoutEr: float, lift: float, n: int}>,
     *     hasAnyData: bool
     * }  $insights
     */
    public function headline(array $insights): ?string
    {
        $format = $insights['formatStats'][0] ?? null;
        $time = $insights['bestTimes'][0] ?? null;

        if ($format === null || $format['n'] < 3 || $time === null) {
            return null;
        }

        $topPattern = $insights['patternLifts'][0] ?? null;
        $traitPhrase = $topPattern !== null && $topPattern['lift'] > 1.2
            ? (self::TRAIT_PHRASES[$topPattern['label']] ?? null)
            : null;

        $article = preg_match('/^[aeiou]/i', $format['format']) === 1 ? 'an' : 'a';
        $trait = $traitPhrase !== null ? ', '.$traitPhrase : '';

        return sprintf(
            'Post %s %s around %s %s%s - that combination is outperforming everything else you are tracking.',
            $article,
            $format['format'],
            $time['day'],
            $this->formatHour($time['hour']),
            $trait,
        );
    }

    /**
     * @param  Collection<int, TrackedAccount>  $rivals
     * @param  Collection<int, Post>  $rivalPosts
     */
    public function suggestedFrequency(Collection $rivals, Collection $rivalPosts): ?int
    {
        $since = now()->subDays(30);
        $stats = [];

        foreach ($rivals as $rival) {
            $cPosts = $rivalPosts->filter(
                fn (Post $post): bool => $post->social_account_id === $rival->social_account_id
                    && $post->posted_at !== null
                    && $post->posted_at->gte($since),
            );

            if ($cPosts->count() < 3) {
                continue;
            }

            $followers = (int) ($rival->followers ?? 0);
            $rates = $cPosts->map(fn (Post $post): float => $this->engagementRateForFollowers($post, $followers))
                ->filter(fn (float $er): bool => $er > 0);

            $stats[] = [
                'perWeek' => ($cPosts->count() / 30) * 7,
                'avgEr' => $rates->isEmpty() ? 0.0 : $rates->avg(),
            ];
        }

        if (count($stats) < 3) {
            return null;
        }

        usort($stats, fn (array $a, array $b): int => $b['avgEr'] <=> $a['avgEr']);
        $topHalf = array_slice($stats, 0, max(1, (int) ceil(count($stats) / 2)));
        $weeks = array_column($topHalf, 'perWeek');
        sort($weeks);
        $mid = (int) floor(count($weeks) / 2);
        $median = count($weeks) % 2 === 0
            ? ($weeks[$mid - 1] + $weeks[$mid]) / 2
            : $weeks[$mid];

        return max(1, (int) round($median));
    }

    /**
     * @param  array{
     *     bestTimes: list<array{day: string, hour: int, er: float, n: int}>,
     *     formatStats: list<array{format: string, er: float, n: int}>,
     *     bestLength: array{label: string, er: float, n: int}|null,
     *     patternLifts: list<array{label: string, withEr: float, withoutEr: float, lift: float, n: int}>,
     *     hasAnyData: bool
     * }  $insights
     * @param  Collection<int, Post>  $ownPosts
     * @return list<array{label: string, detail: string}>
     */
    public function ownAccountGaps(array $insights, Collection $ownPosts, ?int $suggestedFrequency = null): array
    {
        $recent = $ownPosts
            ->filter(fn (Post $post): bool => $post->posted_at !== null)
            ->sortByDesc(fn (Post $post) => $post->posted_at?->timestamp)
            ->take(12)
            ->values();

        if ($recent->isEmpty()) {
            return [];
        }

        $gaps = [];
        $n = $recent->count();

        if ($suggestedFrequency !== null) {
            $own = $this->postsPerWeek($ownPosts);

            if ($suggestedFrequency - $own >= 1) {
                $gaps[] = [
                    'label' => 'Posting frequency',
                    'detail' => sprintf(
                        'Competitors performing well post about %d times a week - you are averaging %s.',
                        $suggestedFrequency,
                        number_format($own, 1),
                    ),
                ];
            }
        }

        $bestFormat = $insights['formatStats'][0] ?? null;

        if ($bestFormat !== null && $bestFormat['n'] >= 3) {
            $used = $recent->filter(fn (Post $post): bool => $this->formatLabel($post->type) === $bestFormat['format'])->count();
            $share = $used / $n;

            if ($used === 0) {
                $gaps[] = [
                    'label' => 'No '.$bestFormat['format'].'s',
                    'detail' => sprintf(
                        'You have not posted a %s in your last %d posts, but it is your competitors best-performing format.',
                        $bestFormat['format'],
                        $n,
                    ),
                ];
            } elseif ($share < 0.25) {
                $gaps[] = [
                    'label' => 'Few '.$bestFormat['format'].'s',
                    'detail' => sprintf(
                        'Only %d of your last %d posts were %ss - it is your competitors best-performing format.',
                        $used,
                        $n,
                        $bestFormat['format'],
                    ),
                ];
            }
        }

        foreach ($insights['patternLifts'] as $pattern) {
            if ($pattern['lift'] <= 1.2) {
                continue;
            }

            $used = $recent->filter(
                fn (Post $post): bool => $this->captionMatchesPattern(trim((string) $post->caption), $pattern['label']),
            )->count();

            if (($used / $n) >= 0.4) {
                continue;
            }

            $phrase = self::GAP_PATTERN_PHRASES[$pattern['label']] ?? strtolower($pattern['label']);
            $gaps[] = [
                'label' => $pattern['label'],
                'detail' => $used === 0
                    ? sprintf(
                        'None of your last %d posts %s, but posts that do are getting %sx the engagement across your competitors.',
                        $n,
                        $phrase,
                        number_format($pattern['lift'], 1),
                    )
                    : sprintf(
                        'Only %d of your last %d posts %s - posts that do get %sx the engagement across your competitors.',
                        $used,
                        $n,
                        $phrase,
                        number_format($pattern['lift'], 1),
                    ),
            ];
        }

        $best = $insights['bestLength'];

        if ($best !== null) {
            $inBucket = $recent->filter(function (Post $post) use ($best): bool {
                $len = mb_strlen(trim((string) $post->caption));

                return match ($best['label']) {
                    '<50' => $len < 50,
                    '50-150' => $len >= 50 && $len < 150,
                    '150-300' => $len >= 150 && $len < 300,
                    default => $len >= 300,
                };
            })->count();

            if ($inBucket === 0) {
                $gaps[] = [
                    'label' => 'Caption length',
                    'detail' => sprintf(
                        'Captions of %s characters perform best across your competitors - none of your last %d posts landed in that range.',
                        $best['label'],
                        $n,
                    ),
                ];
            }
        }

        return array_slice($gaps, 0, 4);
    }

    /**
     * @param  list<string>  $captions
     * @return array{words: list<array{text: string, count: int}>, bigrams: list<array{text: string, count: int}>}
     */
    public function extractPhrases(array $captions): array
    {
        $wordCounts = [];
        $bigramCounts = [];
        $stop = array_fill_keys(self::STOPWORDS, true);

        foreach ($captions as $raw) {
            $cleaned = mb_strtolower($raw);
            $cleaned = preg_replace('/https?:\/\/\S+/', ' ', $cleaned) ?? '';
            $cleaned = preg_replace('/[@#][\p{L}0-9_.]+/u', ' ', $cleaned) ?? '';
            $cleaned = preg_replace("/[^\p{L}\s']/u", ' ', $cleaned) ?? '';
            $tokens = preg_split('/\s+/', $cleaned) ?: [];
            $tokens = array_values(array_filter(array_map(
                function (string $token) use ($stop): ?string {
                    $token = trim($token, "'");

                    if (mb_strlen($token) < 3 || isset($stop[$token]) || preg_match('/^\d+$/', $token) === 1) {
                        return null;
                    }

                    return $token;
                },
                $tokens,
            )));

            foreach ($tokens as $token) {
                $wordCounts[$token] = ($wordCounts[$token] ?? 0) + 1;
            }

            for ($i = 0; $i < count($tokens) - 1; $i++) {
                $phrase = $tokens[$i].' '.$tokens[$i + 1];
                $bigramCounts[$phrase] = ($bigramCounts[$phrase] ?? 0) + 1;
            }
        }

        return [
            'words' => $this->topCounts($wordCounts, 2),
            'bigrams' => $this->topCounts($bigramCounts, 2),
        ];
    }

    public function formatLabel(PostType|string|null $type): string
    {
        $value = $type instanceof PostType ? $type->value : (string) $type;

        return match ($value) {
            'reel', 'video', 'Video' => 'Reel',
            'carousel', 'Sidecar' => 'Carousel',
            'image', 'Image' => 'Image',
            default => 'Other',
        };
    }

    public function likes(Post $post): int
    {
        return (int) data_get($post->metrics, 'likes', 0);
    }

    public function comments(Post $post): int
    {
        return (int) data_get($post->metrics, 'comments', 0);
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @return array<int, int>
     */
    public function followersByAccountId(Collection $accounts): array
    {
        return $accounts
            ->mapWithKeys(fn (TrackedAccount $account): array => [
                (int) $account->social_account_id => (int) ($account->followers ?? 0),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $followersBySocialId
     */
    public function engagementRate(Post $post, array $followersBySocialId): float
    {
        $followers = $followersBySocialId[(int) $post->social_account_id] ?? 0;

        return $this->engagementRateForFollowers($post, $followers);
    }

    public function engagementRateForFollowers(Post $post, int $followers): float
    {
        if ($followers <= 0) {
            return 0.0;
        }

        return (($this->likes($post) + $this->comments($post)) / $followers) * 100;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return Collection<int, Post>
     */
    public function latestPullWindow(Collection $posts): Collection
    {
        $latest = $posts
            ->filter(fn (Post $post): bool => $post->posted_at !== null)
            ->max(fn (Post $post) => $post->posted_at?->getTimestampMs() ?? 0);

        if (! is_numeric($latest) || (int) $latest === 0) {
            return $posts;
        }

        $now = now()->getTimestampMs();
        $windowEnd = min($now, max((int) $latest, $now - 14 * 24 * 60 * 60 * 1000));
        $since = $windowEnd - 7 * 24 * 60 * 60 * 1000;

        return $posts->filter(
            fn (Post $post): bool => $post->posted_at !== null
                && $post->posted_at->getTimestampMs() >= $since,
        )->values();
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    public function postsPerWeek(Collection $posts): float
    {
        $since = now()->subDays(30);
        $n = $posts->filter(
            fn (Post $post): bool => $post->posted_at !== null && $post->posted_at->gte($since),
        )->count();

        return ($n / 30) * 7;
    }

    /**
     * @return list<array{label: string, test: callable(string): bool}>
     */
    private function patternDefs(): array
    {
        return [
            ['label' => 'Asks a question', 'test' => fn (string $c): bool => str_contains($c, '?')],
            ['label' => 'Uses emoji', 'test' => fn (string $c): bool => $this->hasEmoji($c)],
            ['label' => 'Has CTA', 'test' => fn (string $c): bool => $this->hasCta($c)],
            ['label' => 'Uses hashtags', 'test' => fn (string $c): bool => (bool) preg_match('/#\w/', $c)],
            ['label' => 'Tags an account', 'test' => fn (string $c): bool => (bool) preg_match('/@\w/', $c)],
        ];
    }

    private function captionMatchesPattern(string $caption, string $label): bool
    {
        foreach ($this->patternDefs() as $def) {
            if ($def['label'] === $label) {
                return $def['test']($caption);
            }
        }

        return false;
    }

    private function hasCta(string $caption): bool
    {
        return (bool) preg_match(
            '/\b(shop|buy|link in bio|comment|tag|dm|swipe|click|sign up|join|subscribe|enter|win|grab|get yours)\b/i',
            $caption,
        );
    }

    private function hasEmoji(string $caption): bool
    {
        return (bool) preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $caption);
    }

    private function formatHour(int $hour): string
    {
        $suffix = $hour < 12 ? 'am' : 'pm';
        $display = $hour % 12 === 0 ? 12 : $hour % 12;

        return $display.$suffix;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{text: string, count: int}>
     */
    private function topCounts(array $counts, int $min): array
    {
        $rows = [];

        foreach ($counts as $text => $count) {
            if ($count < $min) {
                continue;
            }

            $rows[] = ['text' => $text, 'count' => $count];
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, 10);
    }
}
