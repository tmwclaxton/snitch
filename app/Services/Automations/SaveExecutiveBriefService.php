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
     *     summary: string,
     *     sections?: list<array{title?: string, body: string}>,
     *     suggestions: list<array{title: string, why: string, evidence_post_ids?: list<int>, related_post_ids?: list<int>}>
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
        $result = $this->validator->validate($narrative, $facts);

        if (! ($result['ok'] ?? false)) {
            $errors = array_values(array_map('strval', $result['errors'] ?? ['validation failed']));
            $this->tokens->complete(
                $run,
                AutomationRun::STATUS_FAILED,
                ['validation_errors' => $errors],
                'Brief validation failed; kept the current generated brief.',
            );

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
        $ownSummary = $summary;
        $competitorSummary = $summary;

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
            if (str_contains($title, 'own') || str_contains($title, 'you')) {
                $ownSummary = $body;
            } elseif (str_contains($title, 'competitor') || str_contains($title, 'rival')) {
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
            $actions[] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'why' => trim((string) ($row['why'] ?? '')),
                'how' => trim((string) ($row['how'] ?? 'Follow through today.')),
                'when' => $row['when'] ?? null,
                'format' => $row['format'] ?? null,
                'hook' => $row['hook'] ?? null,
                'related_handles' => $row['related_handles'] ?? [],
                'related_post_ids' => array_values(array_unique(array_filter($ids))),
            ];
        }

        return [
            'headline' => trim((string) ($input['headline'] ?? '')),
            'own_summary' => $ownSummary,
            'competitor_summary' => $competitorSummary,
            'actions' => array_slice($actions, 0, 5),
            'watch' => [],
            'trends' => [],
        ];
    }
}
