<?php

namespace App\Services\Automations;

use App\Models\AutomationRun;
use App\Models\User;

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
     * Chat webhooks are no longer triggered.
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return array{kicked_off: bool, run: ?AutomationRun, reason: ?string}
     */
    public function kickOff(User $user, string $message, array $history = []): array
    {
        return ['kicked_off' => false, 'run' => null, 'reason' => 'removed'];
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
