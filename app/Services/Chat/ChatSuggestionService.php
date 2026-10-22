<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Services\Mcp\McpReadService;

/**
 * Builds empty-state question chips from the user's stored data.
 */
class ChatSuggestionService
{
    public function __construct(private McpReadService $reads) {}

    /**
     * @return list<string>
     */
    public function forUser(User $user): array
    {
        $trackers = $this->reads->listTrackers($user);
        $own = collect($trackers)->firstWhere('is_own_account', true);
        $rival = collect($trackers)->first(fn (array $row): bool => ! ($row['is_own_account'] ?? false));
        $gaps = collect($this->reads->gaps($user))->firstWhere('quiet', true);
        $top = $this->reads->topPosts($user, 7, 1);
        $topHandle = is_array($top[0] ?? null) ? (string) ($top[0]['handle'] ?? '') : '';

        $suggestions = [];

        if (is_array($own) && filled($own['handle'] ?? null)) {
            $suggestions[] = 'How is @'.$own['handle'].' doing this week?';
        } else {
            $suggestions[] = 'How am I doing this week?';
        }

        if (is_array($rival) && filled($rival['handle'] ?? null)) {
            $suggestions[] = 'What is @'.$rival['handle'].' posting lately?';
        } else {
            $suggestions[] = 'Which competitors should I watch?';
        }

        if (is_array($gaps) && filled($gaps['handle'] ?? null)) {
            $suggestions[] = 'Why has @'.$gaps['handle'].' gone quiet?';
        } else {
            $suggestions[] = 'Who has gone quiet recently?';
        }

        if ($topHandle !== '') {
            $suggestions[] = 'Why did @'.$topHandle.'\'s top post work?';
        } else {
            $suggestions[] = 'What are the top posts this month?';
        }

        return array_values(array_unique(array_slice($suggestions, 0, 4)));
    }
}
