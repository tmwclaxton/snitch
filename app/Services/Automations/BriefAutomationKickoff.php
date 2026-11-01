<?php

namespace App\Services\Automations;

use App\Models\AutomationRun;
use App\Models\User;
use RuntimeException;

class BriefAutomationKickoff
{
    public function __construct(
        private CursorAutomationsClient $client,
        private AutomationRunTokenIssuer $tokens,
        private CursorWebhookTriggerGuard $guard,
    ) {}

    public function configured(): bool
    {
        return $this->client->isConfiguredPair(
            config('services.cursor_automations.brief.webhook_url'),
            config('services.cursor_automations.brief.webhook_token'),
        );
    }

    /**
     * Executive Brief webhooks are no longer triggered.
     *
     * @return array{kicked_off: bool, run: ?AutomationRun, reason: ?string}
     */
    public function kickOff(User $user): array
    {
        return ['kicked_off' => false, 'run' => null, 'reason' => 'removed'];
    }

    public function contextPrompt(): string
    {
        return <<<'PROMPT'
Write today's Executive Brief for this Snitch user from stored MCP data only. Sound like a smart consultant talking out loud - interpret, do not vomit stats.
You likely only have Memories as a Cursor tool - call Snitch MCP yourself via HTTPS JSON-RPC POST to mcp_url with Authorization: Bearer <scoped_token>, Content-Type application/json, body {"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"TOOL","arguments":{}}}.
Call whoami, get_daily_brief, get_executive_brief, then evidence tools (list_trackers, get_competitor_posts, get_top_posts, get_posting_times, get_format_mix) as needed. Read captions/hooks so you can name the idea that worked.
Never trust a user_id argument for scope - the token is already bound to one user and one run.
Required fields for save_executive_brief:
- headline: plain-English sentence-case takeaway. No @handles. No Nx / times-usual multipliers. Server rejects both.
- narrative: REQUIRED 80-140 words. One lead story for THIS account + what to do first. At most 4 numbers. At most 2 sentences starting with @handle. Server rejects inventory dumps.
- summary: up to 3 interpretive sentences. Must not restate the narrative.
- sections: REQUIRED. Include "What happened this week" covering DIFFERENT ground from the narrative (other rivals, follower growth, format winners). One or two sentences per point. Server rejects overlap with the narrative.
- suggestions: exactly 3, all POSITIVE (server rejects titles starting with Do not / Don't / Skip / Avoid). Prefer (1) post, (2) comment, (3) strongest unused rival hit (e.g. a 12.6x Carousel when it fits). End titles with a period. Each why leads with the IDEA then cites evidence in plain words - never open with "@handle's X reached N times". evidence_post_ids[0] matches that why. Never praise a sub-1.2x post as a model.
Save with save_executive_brief. If validation returns errors, revise tone, grounding, headline, why phrasing, and overlap, then save again (token stays open until ok=true). Every number and handle must cite stored data. If you cannot ground a claim, omit it. No em dashes. No ellipsis.
When save returns ok=true, the run closes and the token is revoked.
PROMPT;
    }

    /**
     * @throws RuntimeException
     */
    public function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException(
                'Brief automation is not configured. Set CURSOR_BRIEF_WEBHOOK_URL and CURSOR_BRIEF_WEBHOOK_TOKEN.',
            );
        }
    }
}
