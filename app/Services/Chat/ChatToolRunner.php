<?php

namespace App\Services\Chat;

use App\Jobs\GenerateDailyBriefJob;
use App\Models\User;
use App\Services\Brief\DailyBriefGenerator;
use App\Services\Mcp\McpReadService;
use App\Support\DailyBriefPresenter;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * In-app chat tool execution. Same stored-data reads as MCP; never scrapes.
 */
class ChatToolRunner
{
    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'list_trackers',
            'get_account_summary',
            'get_competitor_posts',
            'get_top_posts',
            'get_posting_times',
            'get_format_mix',
            'get_gaps',
            'get_daily_brief',
            'get_growth_series',
        ];
    }

    /**
     * OpenAI-style tool definitions for the chat model.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            $this->fn('list_trackers', 'List the user\'s tracked accounts (own + competitors). Stored data only.', [
                'type' => 'object',
                'properties' => new \stdClass,
            ]),
            $this->fn('get_account_summary', 'Followers, growth over 1/7/30d, and last post for a tracker.', [
                'type' => 'object',
                'properties' => [
                    'handle' => ['type' => 'string', 'description' => 'Tracker handle. Omit for own account.'],
                ],
            ]),
            $this->fn('get_competitor_posts', 'Competitor posts with optional filters, sorted by times-usual by default.', [
                'type' => 'object',
                'properties' => [
                    'handle' => ['type' => 'string'],
                    'format' => ['type' => 'string'],
                    'since' => ['type' => 'string', 'description' => 'Y-m-d'],
                    'sort' => ['type' => 'string', 'enum' => ['times_usual', 'posted_at']],
                    'limit' => ['type' => 'integer'],
                ],
            ]),
            $this->fn('get_top_posts', 'Top posts by times-usual over a window.', [
                'type' => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'enum' => [7, 30, 90]],
                    'limit' => ['type' => 'integer'],
                ],
            ]),
            $this->fn('get_posting_times', 'Best posting time slots from stored posts.', [
                'type' => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'enum' => [7, 30, 90]],
                ],
            ]),
            $this->fn('get_format_mix', 'Own vs competitor format mix.', [
                'type' => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'enum' => [7, 30, 90]],
                ],
            ]),
            $this->fn('get_gaps', 'Quiet trackers and sync gaps from stored posts.', [
                'type' => 'object',
                'properties' => new \stdClass,
            ]),
            $this->fn('get_daily_brief', 'Read the stored daily brief. Does not regenerate.', [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'Y-m-d Europe/London'],
                ],
            ]),
            $this->fn('get_growth_series', 'Follower growth series for the dashboard period.', [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => ['30d', '90d', 'all']],
                ],
            ]),
        ];
    }

    public function __construct(
        private McpReadService $reads,
        private DailyBriefGenerator $dailyBriefs,
        private DailyBriefPresenter $briefPresenter,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function run(User $user, string $name, array $arguments = []): array
    {
        if (! in_array($name, self::toolNames(), true)) {
            throw new InvalidArgumentException('Unknown or disallowed chat tool: '.$name);
        }

        return match ($name) {
            'list_trackers' => [
                'trackers' => $this->reads->listTrackers($user),
            ],
            'get_account_summary' => [
                'summary' => $this->reads->accountSummary(
                    $user,
                    isset($arguments['handle']) ? (string) $arguments['handle'] : null,
                ),
            ],
            'get_competitor_posts' => [
                'posts' => $this->reads->competitorPosts($user, [
                    'handle' => $arguments['handle'] ?? null,
                    'format' => $arguments['format'] ?? null,
                    'since' => $arguments['since'] ?? null,
                    'sort' => $arguments['sort'] ?? 'times_usual',
                    'limit' => isset($arguments['limit']) ? (int) $arguments['limit'] : 20,
                    'include_own' => (bool) ($arguments['include_own'] ?? false),
                ]),
            ],
            'get_top_posts' => [
                'posts' => $this->reads->topPosts(
                    $user,
                    isset($arguments['days']) ? (int) $arguments['days'] : 30,
                    isset($arguments['limit']) ? (int) $arguments['limit'] : 10,
                    (bool) ($arguments['include_own'] ?? false),
                ),
            ],
            'get_posting_times' => $this->reads->postingTimes(
                $user,
                isset($arguments['days']) ? (int) $arguments['days'] : 30,
            ),
            'get_format_mix' => $this->reads->formatMix(
                $user,
                isset($arguments['days']) ? (int) $arguments['days'] : 30,
            ),
            'get_gaps' => [
                'gaps' => $this->reads->gaps($user),
            ],
            'get_daily_brief' => $this->dailyBrief($user, $arguments),
            'get_growth_series' => $this->reads->growthSeries(
                $user,
                isset($arguments['period']) ? (string) $arguments['period'] : '30d',
            ),
            default => throw new InvalidArgumentException('Unknown chat tool: '.$name),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function dailyBrief(User $user, array $arguments): array
    {
        $date = isset($arguments['date']) && is_string($arguments['date']) && $arguments['date'] !== ''
            ? CarbonImmutable::parse($arguments['date'], 'Europe/London')->startOfDay()
            : $this->dailyBriefs->briefDate();

        $brief = $this->dailyBriefs->briefForDate($user, $date);
        $payload = $brief === null ? null : $this->briefPresenter->payload($brief);

        return [
            'date' => $date->toDateString(),
            'brief' => $payload,
            'history' => $this->dailyBriefs->history($user),
            'generating' => GenerateDailyBriefJob::isActiveFor($user->id, $date->toDateString()),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function fn(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }
}
