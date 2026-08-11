<?php

namespace App\Http\Controllers;

use App\Support\McpConnectionGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentsController extends Controller
{
    public function show(Request $request): Response
    {
        $guide = McpConnectionGuide::payload();

        if ($request->user() !== null) {
            return Inertia::render('agents/Index', [
                ...$guide,
                'has_mcp_token' => $request->user()->tokens()->where('name', 'mcp')->exists(),
                'plain_token' => $request->session()->pull('agents.plain_token'),
            ]);
        }

        return Inertia::render('marketing/Agents', $guide);
    }

    public function rotateToken(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $user->tokens()->where('name', 'mcp')->delete();
        $token = $user->createSanctumToken('mcp')->plainTextToken;
        $request->session()->flash('agents.plain_token', $token);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('New API token created. Copy it now - it will not be shown again.'),
        ]);

        return back();
    }
}
