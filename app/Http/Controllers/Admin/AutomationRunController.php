<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Services\Automations\AutomationRunTokenIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
