<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InAppChatService
{
    public function __construct(
        private NanoGptClient $nanoGpt,
        private ChatToolRunner $tools,
    ) {}

    public function dailyCap(): int
    {
        return max(1, (int) config('snitch.chat.daily_message_cap', 40));
    }

    public function usageToday(User $user): int
    {
        return (int) Cache::get($this->usageKey($user), 0);
    }

    public function remainingToday(User $user): int
    {
        return max(0, $this->dailyCap() - $this->usageToday($user));
    }

    public function isCapped(User $user): bool
    {
        return $this->remainingToday($user) <= 0;
    }

    public function incrementUsage(User $user): void
    {
        $key = $this->usageKey($user);
        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->timezone('Europe/London')->endOfDay());

            return;
        }

        Cache::increment($key);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function streamReply(User $user, string $message, array $history = []): StreamedResponse
    {
        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException('Message is required.');
        }

        if ($this->isCapped($user)) {
            return $this->cappedStream($user);
        }

        $this->incrementUsage($user);

        $model = (string) config('snitch.chat.model');
        $maxRounds = max(1, (int) config('snitch.chat.max_tool_rounds', 4));

        $messages = [
            [
                'role' => 'system',
                'content' => $this->systemPrompt($user),
            ],
            ...array_values(array_filter(
                $history,
                fn (array $row): bool => in_array($row['role'] ?? null, ['user', 'assistant'], true)
                    && is_string($row['content'] ?? null)
                    && trim((string) $row['content']) !== '',
            )),
            [
                'role' => 'user',
                'content' => $message,
            ],
        ];

        return response()->stream(function () use ($user, $messages, $model, $maxRounds): void {
            try {
                $finalText = $this->completeWithTools($user, $messages, $model, $maxRounds);
                $this->emit(['type' => 'status', 'message' => null]);
                $this->emitChunks($finalText);
                $this->emit(['type' => 'done', 'remaining' => $this->remainingToday($user)]);
            } catch (\Throwable $e) {
                $this->emit(['type' => 'status', 'message' => null]);
                $this->emit([
                    'type' => 'error',
                    'message' => 'Chat failed. Try again in a moment.',
                ]);
                report($e);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function cappedStream(User $user): StreamedResponse
    {
        return response()->stream(function (): void {
            $this->emit([
                'type' => 'capped',
                'message' => 'You have hit today\'s in-app chat limit. Connect your AI client to keep asking with MCP.',
                'mcp_path' => '/settings/mcp',
                'remaining' => 0,
                'daily_cap' => $this->dailyCap(),
            ]);
            $this->emit(['type' => 'done', 'remaining' => 0]);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function completeWithTools(User $user, array $messages, string $model, int $maxRounds): string
    {
        $toolDefs = $this->tools->definitions();

        for ($round = 0; $round < $maxRounds; $round++) {
            $response = $this->nanoGpt->chat($messages, $model, [
                'tools' => $toolDefs,
                'tool_choice' => 'auto',
                'temperature' => (float) config('snitch.chat.temperature', 0.3),
                'max_tokens' => (int) config('snitch.chat.max_tokens', 2048),
                'timeout' => (int) config('snitch.chat.timeout', 120),
            ]);

            $choice = $response['choices'][0] ?? null;
            if (! is_array($choice)) {
                throw new RuntimeException('Chat model returned no choices.');
            }

            $message = $choice['message'] ?? null;
            if (! is_array($message)) {
                throw new RuntimeException('Chat model returned an empty message.');
            }

            $toolCalls = $message['tool_calls'] ?? null;
            if (is_array($toolCalls) && $toolCalls !== []) {
                $this->emit([
                    'type' => 'status',
                    'message' => 'Checking your data...',
                ]);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();

                $messages[] = [
                    'role' => 'assistant',
                    'content' => $message['content'] ?? null,
                    'tool_calls' => $toolCalls,
                ];

                $collectedPosts = [];

                foreach ($toolCalls as $call) {
                    if (! is_array($call)) {
                        continue;
                    }
                    $id = (string) ($call['id'] ?? uniqid('call_', true));
                    $fn = is_array($call['function'] ?? null) ? $call['function'] : [];
                    $name = (string) ($fn['name'] ?? '');
                    $rawArgs = $fn['arguments'] ?? '{}';
                    $decoded = is_string($rawArgs) ? json_decode($rawArgs, true) : $rawArgs;
                    $args = is_array($decoded) ? $decoded : [];

                    try {
                        $result = $this->tools->run($user, $name, $args);
                        $collectedPosts = array_merge($collectedPosts, $this->extractPosts($result));
                        $content = json_encode($result, JSON_UNESCAPED_SLASHES) ?: '{}';
                    } catch (\Throwable $e) {
                        $content = json_encode([
                            'error' => $e->getMessage(),
                        ], JSON_UNESCAPED_SLASHES) ?: '{"error":"tool failed"}';
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $id,
                        'content' => $content,
                    ];
                }

                if ($collectedPosts !== []) {
                    $this->emit(['type' => 'posts', 'posts' => array_values($collectedPosts)]);
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }

                continue;
            }

            $text = $this->nanoGpt->extractAssistantText($response);
            if ($text !== '') {
                return $this->sanitizeAssistantText($text);
            }

            throw new RuntimeException('Chat model returned an empty reply.');
        }

        throw new RuntimeException('Chat stopped after too many tool rounds.');
    }

    private function emitChunks(string $text): void
    {
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $buffer = '';

        foreach ($parts as $part) {
            $buffer .= $part;
            if (mb_strlen($buffer) >= 24) {
                $this->emit(['type' => 'delta', 'text' => $buffer]);
                $buffer = '';
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }

        if ($buffer !== '') {
            $this->emit(['type' => 'delta', 'text' => $buffer]);
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }
    }

    /**
     * Strip em/en dashes from model output. Prefer colon before a number, otherwise a comma.
     */
    public function sanitizeAssistantText(string $text): string
    {
        $text = preg_replace('/\s*[\x{2014}\x{2013}]\s*(?=\d)/u', ': ', $text) ?? $text;
        $text = preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', $text) ?? $text;

        return $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
    }

    private function systemPrompt(User $user): string
    {
        return implode("\n", [
            'You are Snitch chat for '.$user->name.'.',
            'Answer using only the provided tools, which read stored Snitch data for this user.',
            'Never invent metrics. If a tool returns empty data, say so plainly.',
            'Do not trigger scrapes, billing changes, or account mutations.',
            'Keep replies concise. Use plain words next to numbers.',
            'Never use em dashes or en dashes. Use commas or colons instead (example: "letsgosocialuk: 104 followers").',
            'Prefer short paragraphs and real markdown lists (hyphen bullets or numbered). Bold key labels with **label**.',
            'Prefix Instagram handles with @ so they can be linked (example: @letsgosocialuk).',
            'When citing a post, include the full Instagram post or reel URL on its own line so it can render as a card.',
            'Prefer short paragraphs over long essays.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function extractPosts(array $result): array
    {
        $posts = [];
        $candidates = [];

        if (isset($result['posts']) && is_array($result['posts'])) {
            $candidates = $result['posts'];
        } elseif (isset($result['summary']['last_post']) && is_array($result['summary']['last_post'])) {
            $candidates = [$result['summary']['last_post']];
        }

        foreach ($candidates as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = isset($row['url']) ? (string) $row['url'] : '';
            if ($url === '' || ! str_contains(strtolower($url), 'instagram.com')) {
                continue;
            }
            $posts[$url] = [
                'url' => $url,
                'handle' => isset($row['handle']) ? (string) $row['handle'] : null,
                'caption' => isset($row['caption']) ? (string) $row['caption'] : null,
                'thumbnail_url' => isset($row['thumbnail_url']) ? (string) $row['thumbnail_url'] : (isset($row['cover_url']) ? (string) $row['cover_url'] : null),
                'likes' => isset($row['likes']) && is_numeric($row['likes']) ? (int) $row['likes'] : null,
                'comments' => isset($row['comments']) && is_numeric($row['comments']) ? (int) $row['comments'] : null,
                'times_usual_label' => isset($row['times_usual_label']) ? (string) $row['times_usual_label'] : null,
            ];
        }

        return array_values($posts);
    }

    private function usageKey(User $user): string
    {
        $day = CarbonImmutable::now('Europe/London')->toDateString();

        return 'chat:messages:'.$user->id.':'.$day;
    }
}
