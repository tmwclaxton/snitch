<?php

namespace App\Services\Automations;

use App\Models\AutomationRun;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
     * @return array{kicked_off: bool, run: ?AutomationRun, reason: ?string}
     */
    public function kickOff(User $user): array
    {
        if (! $this->configured()) {
            return ['kicked_off' => false, 'run' => null, 'reason' => 'not_configured'];
        }

        $handles = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->pluck('handle')
            ->filter(fn ($h) => is_string($h) && trim($h) !== '')
            ->map(fn (string $h) => ltrim(trim($h), '@'))
            ->values()
            ->all();

        if ($handles === []) {
            return ['kicked_off' => false, 'run' => null, 'reason' => 'no_trackers'];
        }

        if ($this->guard->recentlyTriggered(AutomationRun::TYPE_BRIEF, (int) $user->id)) {
            return ['kicked_off' => false, 'run' => null, 'reason' => 'recently_triggered'];
        }

        $issued = $this->tokens->issue($user, AutomationRun::TYPE_BRIEF, [
            'handles' => $handles,
            'mode' => 'executive_brief',
        ]);
        /** @var AutomationRun $run */
        $run = $issued['run'];
        $plainToken = $issued['plain_text_token'];

        $payload = [
            'user_id' => (int) $user->id,
            'handles' => $handles,
            'run_id' => $run->uuid,
            'scoped_token' => $plainToken,
            'mcp_url' => (string) config('services.cursor_automations.mcp_url', 'https://www.snitchsocial.net/mcp'),
            'mode' => 'executive_brief',
            'context' => $this->contextPrompt(),
        ];

        try {
            $this->guard->mark(AutomationRun::TYPE_BRIEF, (int) $user->id);
            $result = $this->client->trigger(
                (string) config('services.cursor_automations.brief.webhook_url'),
                (string) config('services.cursor_automations.brief.webhook_token'),
                $payload,
            );
        } catch (Throwable $e) {
            $this->tokens->complete($run, AutomationRun::STATUS_FAILED, null, $e->getMessage());
            Log::warning('brief_automation_kickoff_failed', [
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
                'Webhook returned without a run id. A run may already be in flight.',
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
