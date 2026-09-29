<?php

namespace App\Http\Controllers;

use App\Support\McpConnectionGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentsController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return to_route('settings.mcp.show');
        }

        return Inertia::render('marketing/Agents', McpConnectionGuide::payload());
    }
}
