<?php

namespace App\Http\Controllers;

use App\Services\Chat\ChatToolRunner;
use App\Services\Chat\InAppChatService;
use App\Support\McpConnectionGuide;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function index(Request $request, InAppChatService $chat): Response
    {
        $user = $request->user();
        $guide = McpConnectionGuide::payload();

        return Inertia::render('chat/Index', [
            'daily_cap' => $chat->dailyCap(),
            'remaining' => $chat->remainingToday($user),
            'capped' => $chat->isCapped($user),
            'mcp_path' => '/settings/mcp',
            'mcp_url' => $guide['mcp_url'] ?? url('/mcp'),
            'tools' => ChatToolRunner::toolNames(),
        ]);
    }

    public function stream(Request $request, InAppChatService $chat): StreamedResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:8000'],
        ]);

        /** @var list<array{role: string, content: string}> $history */
        $history = is_array($data['history'] ?? null) ? $data['history'] : [];

        return $chat->streamReply($request->user(), (string) $data['message'], $history);
    }
}
