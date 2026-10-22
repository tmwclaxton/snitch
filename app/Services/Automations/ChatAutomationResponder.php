<?php

namespace App\Services\Automations;

use App\Models\AutomationRun;
use App\Models\User;
use App\Services\Chat\InAppChatService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Prefer Cursor chat automation when configured; fall back to in-app LLM on
 * missing config, kickoff failure, or timeout.
 */
class ChatAutomationResponder
{
    public function __construct(
        private ChatAutomationKickoff $kickoff,
        private InAppChatService $fallback,
        private AutomationRunTokenIssuer $tokens,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function stream(User $user, string $message, array $history = []): StreamedResponse
    {
        if (! $this->kickoff->configured()) {
            return $this->fallback->streamReply($user, $message, $history);
        }

        if ($this->fallback->isCapped($user)) {
            return $this->fallback->streamReply($user, $message, $history);
        }

        $result = $this->kickoff->kickOff($user, $message, $history);
        if (! $result['kicked_off'] || $result['run'] === null) {
            return $this->fallback->streamReply($user, $message, $history);
        }

        $this->fallback->incrementUsage($user);
        $runId = (int) $result['run']->id;
        $timeoutAt = microtime(true) + $this->kickoff->timeoutSeconds();

        return response()->stream(function () use ($user, $message, $history, $runId, $timeoutAt): void {
            $this->emit([
                'type' => 'status',
                'message' => 'Cursor automation is drafting a reply…',
                'mode' => 'automation',
            ]);

            $sentLength = 0;
            $lastStatus = null;

            while (microtime(true) < $timeoutAt) {
                /** @var AutomationRun|null $run */
                $run = AutomationRun::query()->find($runId);
                if ($run === null) {
                    break;
                }

                $partial = (string) ($run->reply_partial ?? '');
                if (mb_strlen($partial) > $sentLength) {
                    $delta = mb_substr($partial, $sentLength);
                    $sentLength = mb_strlen($partial);
                    $this->emit(['type' => 'status', 'message' => null]);
                    $this->emit(['type' => 'delta', 'text' => $delta]);
                }

                if ($run->status === AutomationRun::STATUS_SUCCEEDED && filled($run->reply_final)) {
                    if ($sentLength === 0) {
                        $this->emit(['type' => 'status', 'message' => null]);
                        $this->emit(['type' => 'delta', 'text' => (string) $run->reply_final]);
                    }
                    $this->emit([
                        'type' => 'done',
                        'remaining' => $this->fallback->remainingToday($user),
                        'mode' => 'automation',
                    ]);

                    return;
                }

                if (in_array($run->status, [
                    AutomationRun::STATUS_FAILED,
                    AutomationRun::STATUS_REVOKED,
                    AutomationRun::STATUS_TIMED_OUT,
                ], true)) {
                    $lastStatus = $run->status;
                    break;
                }

                usleep(400_000);
            }

            /** @var AutomationRun|null $open */
            $open = AutomationRun::query()->find($runId);
            if ($open !== null && $open->isOpen()) {
                $this->tokens->complete(
                    $open,
                    AutomationRun::STATUS_TIMED_OUT,
                    null,
                    'Chat automation timed out; falling back to in-app LLM.',
                );
                $lastStatus = AutomationRun::STATUS_TIMED_OUT;
            }

            $this->emit([
                'type' => 'status',
                'message' => 'Automation timed out - falling back to the in-app model.',
                'mode' => 'fallback',
                'automation_status' => $lastStatus,
            ]);

            // Drop any partial automation text so the fallback is a clean answer.
            if ($sentLength > 0) {
                $this->emit(['type' => 'reset']);
            }

            $this->fallback->writeLlmReplyToOpenStream($user, $message, $history);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
}
