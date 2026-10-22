<?php

namespace App\Services\Automations;

use App\Models\AutomationRun;
use App\Models\DailyBrief;
use App\Models\User;
use App\Services\Brief\DailyBriefGenerator;
use App\Services\Brief\DailyBriefValidator;
use App\Services\Brief\ExecutiveBriefPresenter;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SaveExecutiveBriefService
{
    public function __construct(
        private DailyBriefGenerator $generator,
        private DailyBriefValidator $validator,
        private ExecutiveBriefPresenter $presenter,
        private AutomationRunTokenIssuer $tokens,
    ) {}

    /**
     * @param  array{
     *     headline: string,
     *     narrative: string,
     *     summary: string,
     *     sections?: list<array{title?: string, body: string}>,
     *     suggestions: list<array{title: string, why: string, expected?: string, evidence_post_ids?: list<int>, related_post_ids?: list<int>}>
     * }  $input
     * @return array{ok: bool, kept_existing: bool, brief: array<string, mixed>|null, errors: list<string>, run: AutomationRun}
     */
    public function save(User $user, AutomationRun $run, array $input): array
    {
        if ($run->type !== AutomationRun::TYPE_BRIEF || (int) $run->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'run' => ['This token is not authorised to save an Executive Brief for that run.'],
            ]);
        }

        if (! $run->isOpen()) {
            throw ValidationException::withMessages([
                'run' => ['This automation run is already closed.'],
            ]);
        }

        $brief = $this->readyBrief($user);
        if ($brief === null) {
            $this->tokens->complete($run, AutomationRun::STATUS_FAILED, null, 'No ready brief to update.');

            return [
                'ok' => false,
                'kept_existing' => true,
                'brief' => null,
                'errors' => ['No ready Executive Brief exists yet.'],
                'run' => $run->fresh() ?? $run,
            ];
        }

        $facts = is_array($brief->facts) ? $brief->facts : [];
        $narrative = $this->toNarrativeShape($input);
        // Rebind every action's title/why to related_post_ids[0] so evidence chips match the prose.
        $narrative = $this->generator->alignActionCitations($narrative, $facts);
        $result = $this->validator->validate($narrative, $facts);

        if (! ($result['ok'] ?? false)) {
            $errors = array_values(array_map('strval', $result['errors'] ?? ['validation failed']));
            // Keep the run open and the token alive so the agent can fix and resubmit.
            $meta = is_array($run->result_meta) ? $run->result_meta : [];
            $meta['validation_errors'] = $errors;
            $meta['validation_attempts'] = (int) ($meta['validation_attempts'] ?? 0) + 1;
            $run->forceFill(['result_meta' => $meta])->save();

            return [
                'ok' => false,
                'kept_existing' => true,
                'brief' => $this->presenter->fromBrief($brief),
                'errors' => $errors,
                'run' => $run->fresh() ?? $run,
            ];
        }

        /** @var array<string, mixed> $checked */
        $checked = $result['output'];
        $payload = is_array($brief->payload) ? $brief->payload : [];

        $payload['headline'] = (string) ($checked['headline'] ?? $input['headline']);
        $payload['narrative'] = trim((string) ($checked['narrative'] ?? ($input['narrative'] ?? '')));
        $payload['summary'] = trim((string) $input['summary']);
        $payload['own_summary'] = (string) ($checked['own_summary'] ?? '');
        $payload['competitor_summary'] = (string) ($checked['competitor_summary'] ?? $payload['summary']);
        $payload['actions'] = $checked['actions'] ?? [];
        $payload['watch'] = $checked['watch'] ?? ($payload['watch'] ?? []);
        $payload['automation_overrides'] = [
            'updated_at' => CarbonImmutable::now(DashboardMath::TIMEZONE)->toIso8601String(),
            'run_uuid' => $run->uuid,
            'sections' => $input['sections'] ?? [],
        ];

        $brief->headline = (string) $payload['headline'];
        $brief->payload = $payload;
        $brief->save();

        $this->tokens->complete($run, AutomationRun::STATUS_SUCCEEDED, [
            'brief_id' => $brief->id,
            'brief_date' => $brief->brief_date?->toDateString(),
        ]);

        return [
            'ok' => true,
            'kept_existing' => false,
            'brief' => $this->presenter->fromBrief($brief->fresh() ?? $brief),
            'errors' => [],
            'run' => $run->fresh() ?? $run,
        ];
    }

    private function readyBrief(User $user): ?DailyBrief
    {
        $date = $this->generator->briefDate();
        $brief = $this->generator->briefForDate($user, $date);

        if ($brief !== null && $brief->status === 'ready') {
            return $brief;
        }

        return DailyBrief::query()
            ->where('user_id', $user->id)
            ->where('status', 'ready')
            ->orderByDesc('brief_date')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function toNarrativeShape(array $input): array
    {
        $summary = trim((string) ($input['summary'] ?? ''));
        // Do not copy summary into own/competitor - that made "What happened" repeat the narrative.
        $ownSummary = '';
        $competitorSummary = '';

        $sections = is_array($input['sections'] ?? null) ? $input['sections'] : [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $title = mb_strtolower(trim((string) ($section['title'] ?? '')));
            $body = trim((string) ($section['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            if (str_contains($title, 'own') || str_contains($title, 'you\'ve') || str_contains($title, 'you have')) {
                $ownSummary = $body;
            } elseif (
                str_contains($title, 'competitor')
                || str_contains($title, 'rival')
                || str_contains($title, 'happened')
                || str_contains($title, 'this week')
            ) {
                $competitorSummary = $body;
            }
        }

        $actions = [];
        $suggestions = is_array($input['suggestions'] ?? null) ? $input['suggestions'] : [];
        foreach (array_values($suggestions) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ids = [];
            foreach ($row['evidence_post_ids'] ?? $row['related_post_ids'] ?? [] as $id) {
                $ids[] = (int) $id;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title !== '' && ! preg_match('/[.!?]$/u', $title)) {
                $title .= '.';
            }

            $expected = trim((string) ($row['expected'] ?? ''));

            $actions[] = [
                'title' => $title,
                'why' => trim((string) ($row['why'] ?? '')),
                'how' => trim((string) ($row['how'] ?? 'Follow through today.')),
                'expected' => $expected !== '' ? $expected : null,
                'when' => $row['when'] ?? null,
                'format' => $row['format'] ?? null,
                'hook' => $row['hook'] ?? null,
                'related_handles' => $row['related_handles'] ?? [],
                'related_post_ids' => array_values(array_unique(array_filter($ids))),
            ];
        }

        return [
            'headline' => trim((string) ($input['headline'] ?? '')),
            'narrative' => trim((string) ($input['narrative'] ?? '')),
            'own_summary' => $ownSummary,
            'competitor_summary' => $competitorSummary,
            'actions' => array_slice($actions, 0, 5),
            'watch' => [],
            'trends' => [],
        ];
    }
}
