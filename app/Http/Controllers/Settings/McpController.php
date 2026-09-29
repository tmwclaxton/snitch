<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\McpConnectionGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class McpController extends Controller
{
    /**
     * Show the signed-in MCP connection guide and token controls.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        return Inertia::render('settings/Mcp', [
            ...McpConnectionGuide::payload(),
            'has_mcp_token' => $user->sanctumTokens()->where('name', 'mcp')->exists(),
            'plain_token' => $request->session()->pull('agents.plain_token'),
        ]);
    }

    /**
     * Replace the Sanctum token used by HTTP MCP clients.
     */
    public function rotateToken(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $user->sanctumTokens()->where('name', 'mcp')->delete();
        $token = $user->createSanctumToken('mcp')->plainTextToken;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('New API token created. Copy it now - it will not be shown again.'),
        ]);

        return to_route('settings.mcp.show')->with('agents.plain_token', $token);
    }
}
