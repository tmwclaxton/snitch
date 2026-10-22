<?php

namespace App\Services\Automations;

use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PostChatReplyService
{
    public function __construct(
        private AutomationRunTokenIssuer $tokens,
    ) {}

    /**
     * @return array{ok: bool, partial: bool, run: AutomationRun, reply: string}
     */
    public function post(User $user, AutomationRun $run, string $text, bool $partial = false, bool $done = false): array
    {
        if ($run->type !== AutomationRun::TYPE_CHAT || (int) $run->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'run' => ['This token is not authorised to post a chat reply for that run.'],
            ]);
        }

        if (! $run->isOpen()) {
            throw ValidationException::withMessages([
                'run' => ['This automation run is already closed.'],
            ]);
        }

        $text = trim($text);
        if ($text === '' && ! $done) {
            throw ValidationException::withMessages([
                'text' => ['Reply text is required unless done=true.'],
            ]);
        }

        if (str_contains($text, '—') || str_contains($text, '–')) {
            throw ValidationException::withMessages([
                'text' => ['Reply must not use em or en dashes.'],
            ]);
        }

        $isFinal = $done || ! $partial;

        if ($partial && ! $isFinal) {
            $existing = (string) ($run->reply_partial ?? '');
            $combined = $existing === '' ? $text : $this->mergePartial($existing, $text);
            $run->forceFill([
                'reply_partial' => $combined,
                'status' => AutomationRun::STATUS_RUNNING,
            ])->save();

            return [
                'ok' => true,
                'partial' => true,
                'run' => $run->fresh() ?? $run,
                'reply' => $combined,
            ];
        }

        $final = $text !== '' ? $text : (string) ($run->reply_partial ?? '');
        if ($final === '') {
            throw ValidationException::withMessages([
                'text' => ['Final reply text is required.'],
            ]);
        }

        $run->forceFill([
            'reply_partial' => $final,
            'reply_final' => $final,
        ])->save();

        $this->tokens->complete($run, AutomationRun::STATUS_SUCCEEDED, [
            'reply_chars' => mb_strlen($final),
        ]);

        return [
            'ok' => true,
            'partial' => false,
            'run' => $run->fresh() ?? $run,
            'reply' => $final,
        ];
    }

    private function mergePartial(string $existing, string $incoming): string
    {
        // If the agent resends a growing prefix, keep the longer string.
        if (str_starts_with($incoming, $existing)) {
            return $incoming;
        }

        if (str_starts_with($existing, $incoming)) {
            return $existing;
        }

        return $existing.$incoming;
    }
}
