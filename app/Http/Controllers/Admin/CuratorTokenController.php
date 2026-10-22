<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mcp\Support\McpAuth;
use App\Models\CuratorMcpAudit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class CuratorTokenController extends Controller
{
    public function index(): Response
    {
        $tokens = PersonalAccessToken::query()
            ->where('name', McpAuth::CURATOR_TOKEN_NAME)
            ->where('tokenable_type', User::class)
            ->orderByDesc('id')
            ->get()
            ->map(function (PersonalAccessToken $token): array {
                /** @var User|null $user */
                $user = $token->tokenable;

                return [
                    'id' => (int) $token->id,
                    'user_id' => $user?->id,
                    'user_email' => $user?->email,
                    'user_name' => $user?->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $audits = CuratorMcpAudit::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (CuratorMcpAudit $row): array => [
                'id' => (int) $row->id,
                'tool' => (string) $row->tool,
                'actor_user_id' => $row->actor_user_id,
                'subject_user_id' => $row->subject_user_id,
                'ok' => (bool) $row->ok,
                'error_code' => $row->error_code,
                'duration_ms' => $row->duration_ms,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('admin/CuratorTokens', [
            'tokens' => $tokens,
            'audits' => $audits,
        ]);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $deleted = PersonalAccessToken::query()
            ->where('id', $token)
            ->where('name', McpAuth::CURATOR_TOKEN_NAME)
            ->where('tokenable_type', User::class)
            ->delete();

        Inertia::flash('toast', [
            'type' => $deleted > 0 ? 'success' : 'error',
            'message' => $deleted > 0
                ? __('Curator token revoked.')
                : __('Curator token not found.'),
        ]);

        return to_route('admin.curator-tokens.index');
    }
}
