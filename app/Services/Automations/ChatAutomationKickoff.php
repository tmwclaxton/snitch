<?php

namespace App\Services\Automations;

use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatAutomationKickoff
{
    public function __construct(
        private CursorAutomationsClient $client,
        private AutomationRunTokenIssuer $tokens,
        private CursorWebhookTriggerGuard $guard,
    ) {}

    public function configured(): bool
    {
        return $this->client->isConfiguredPair(
            config('services.cursor_automations.chat.webhook_url'),
            config('services.cursor_automations.chat.webhook_token'),
        );
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{kicked_off: bool, run: ?AutomationRun, reason: ?string}
     */
    public function kickOff(User $user, string $message, array $history = []): array
    {
        if (! $this->configured()) {
            return ['kicked_off' => false, 'run' => null, 'reason' => 'not_configured'];
        }

        $message = trim($message);
        if ($message === '') {
            return ['kicked_off' => false, 'run' => null, 'reason' => 'empty_message'];
        }

        // Guard per user+message hash so rapid double-submits do not fork agents.
        $extra = substr(hash('sha256', $message), 0, 16);
        if ($this->guard->recentlyTriggered(AutomationRun::TYPE_CHAT, (int) $user->id, $extra)) {
            return ['kicked_off' => false, 'run' => null, 'reason' => 'recently_triggered'];
        }

        $conversation = array_values(array_filter(
            $history,
            fn (array $row): bool => in_array($row['role'] ?? null, ['user', 'assistant'], true)
                && is_string($row['content'] ?? null)
                && trim((string) $row['content']) !== '',
        ));
        $conversation[] = ['role' => 'user', 'content' => $message];

        $issued = $this->tokens->issue($user, AutomationRun::TYPE_CHAT, [
            'mode' => 'in_app_chat',
            'message_preview' => mb_substr($message, 0, 200),
        ]);
        /** @var AutomationRun $run */
        $run = $issued['run'];
        $plainToken = $issued['plain_text_token'];

        $run->forceFill([
            'user_message' => $message,
            'conversation' => $conversation,
        ])->save();

        $payload = [
            'user_id' => (int) $user->id,
            'run_id' => $run->uuid,
            'scoped_token' => $plainToken,
            'mcp_url' => (string) config('services.cursor_automations.mcp_url', 'https://www.snitchsocial.net/mcp'),
            'mode' => 'in_app_chat',
            'message' => $message,
            'conversation' => $conversation,
            'context' => $this->contextPrompt(),
        ];

        try {
            $this->guard->mark(AutomationRun::TYPE_CHAT, (int) $user->id, $extra);
            $result = $this->client->trigger(
                (string) config('services.cursor_automations.chat.webhook_url'),
                (string) config('services.cursor_automations.chat.webhook_token'),
                $payload,
            );
        } catch (Throwable $e) {
            $this->tokens->complete($run, AutomationRun::STATUS_FAILED, null, $e->getMessage());
            Log::warning('chat_automation_kickoff_failed', [
                'user_id' => $user->id,
                'run_uuid' => $run->uuid,
                'error' => $e->getMessage(),
            ]);

            return ['kicked_off' => false, 'run' => $run->fresh(), 'reason' => 'webhook_failed'];
        }

        if ($result['cursor_run_id'] === null && $result['cursor_background_composer_id'] === null) {
            $this->tokens->complete(
                $run,
                AutomationRun::STATUS_FAILED,
                ['webhook_response' => $result['raw']],
                'Webhook returned without a run id.',
            );

            return ['kicked_off' => false, 'run' => $run->fresh(), 'reason' => 'missing_run_id'];
        }

        $run->forceFill([
            'cursor_run_id' => $result['cursor_run_id'],
            'cursor_background_composer_id' => $result['cursor_background_composer_id'],
            'status' => AutomationRun::STATUS_RUNNING,
        ])->save();

        return ['kicked_off' => true, 'run' => $run->fresh(), 'reason' => null];
    }

    public function contextPrompt(): string
    {
        return <<<'PROMPT'
Answer this Snitch user's in-app chat question from stored MCP data only.
Authenticate to mcp_url with Authorization: Bearer <scoped_token> from this payload.
Use read tools for their brand, trackers, posts, briefs, and growth. Never invent numbers.
Never trust a user_id argument for scope - the token is already bound to one user and one run.
Reply with post_chat_reply. You may call it multiple times with partial=true to stream text, then once with partial=false (or done=true) for the final answer.
When the final reply is posted, the run closes and the token is revoked.
PROMPT;
    }

    public function timeoutSeconds(): int
    {
        return max(30, (int) config('services.cursor_automations.chat.timeout_seconds', 180));
    }
}
