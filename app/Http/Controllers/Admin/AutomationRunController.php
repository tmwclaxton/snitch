<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Models\User;
use App\Services\Automations\AutomationRunTokenIssuer;
use App\Services\Automations\BriefAutomationKickoff;
use App\Services\Automations\CursorWebhookTriggerGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AutomationRunController extends Controller
{
    public function index(): Response
    {
        $runs = AutomationRun::query()
            ->with('user:id,name,email')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AutomationRun $run): array => [
                'id' => (int) $run->id,
                'uuid' => (string) $run->uuid,
                'user_id' => (int) $run->user_id,
                'user_email' => $run->user?->email,
                'user_name' => $run->user?->name,
                'type' => (string) $run->type,
                'status' => (string) $run->status,
                'cursor_run_id' => $run->cursor_run_id,
                'cursor_background_composer_id' => $run->cursor_background_composer_id,
                'cost_notes' => $run->cost_notes,
                'error' => $run->error,
                'result_meta' => is_array($run->result_meta) ? $run->result_meta : null,
                'has_token' => $run->personal_access_token_id !== null,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'expires_at' => $run->expires_at?->toIso8601String(),
                'created_at' => $run->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('admin/AutomationRuns', [
            'runs' => $runs,
            'brief_configured' => filled(config('services.cursor_automations.brief.webhook_url'))
                && filled(config('services.cursor_automations.brief.webhook_token')),
            'chat_configured' => filled(config('services.cursor_automations.chat.webhook_url'))
                && filled(config('services.cursor_automations.chat.webhook_token')),
        ]);
    }

    public function kickoffBrief(
        Request $request,
        BriefAutomationKickoff $kickoff,
        CursorWebhookTriggerGuard $guard,
    ): RedirectResponse {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()->find((int) $data['user_id']);
        if ($user === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('User not found.'),
            ]);

            return to_route('admin.automation-runs.index');
        }

        if (! $kickoff->configured()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Brief webhook is not configured.'),
            ]);

            return to_route('admin.automation-runs.index');
        }

        if ($request->boolean('force')) {
            $guard->forget(AutomationRun::TYPE_BRIEF, (int) $user->id);
        }

        try {
            $result = $kickoff->kickOff($user);
        } catch (Throwable $e) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);

            return to_route('admin.automation-runs.index');
        }

        if (! $result['kicked_off']) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Brief kickoff skipped: :reason', ['reason' => (string) $result['reason']]),
            ]);

            return to_route('admin.automation-runs.index');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Brief automation kicked off for user :id (run :uuid).', [
                'id' => $user->id,
                'uuid' => $result['run']?->uuid ?? '?',
            ]),
        ]);

        return to_route('admin.automation-runs.index');
    }

    public function revoke(Request $request, AutomationRun $run, AutomationRunTokenIssuer $tokens): RedirectResponse
    {
        $tokens->revoke($run, AutomationRun::STATUS_REVOKED, 'Revoked by admin.');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Automation run revoked.'),
        ]);

        return to_route('admin.automation-runs.index');
    }
}
