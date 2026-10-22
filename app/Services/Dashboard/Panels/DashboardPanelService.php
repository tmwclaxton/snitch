<?php

namespace App\Services\Dashboard\Panels;

use App\Models\DashboardPanel;
use App\Models\DashboardPanelVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardPanelService
{
    public function __construct(
        private DashboardPanelSchema $schema,
        private DefaultDashboardPanels $defaults,
    ) {}

    /**
     * Render-ready panels for the dashboard page (never empty payloads).
     *
     * @return array{panels: list<array<string, mixed>>, source: string}
     */
    public function forDashboard(User $user): array
    {
        $stored = DashboardPanel::query()
            ->where('user_id', $user->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($stored->isNotEmpty()) {
            $panels = $stored
                ->map(fn (DashboardPanel $panel): array => $this->toRenderArray($panel))
                ->filter(fn (array $panel): bool => $this->schema->hasData($panel))
                ->values()
                ->all();

            if ($panels !== []) {
                return ['panels' => $panels, 'source' => 'stored'];
            }
        }

        $defaults = array_values(array_filter(
            $this->defaults->forUser($user),
            fn (array $panel): bool => $this->schema->hasData($panel),
        ));

        return [
            'panels' => array_map(fn (array $panel, int $index): array => [
                'id' => null,
                'position' => $index,
                'type' => $panel['type'],
                'title' => $panel['title'],
                'payload' => $panel['payload'],
                'source' => $panel['source'],
            ], $defaults, array_keys($defaults)),
            'source' => 'default',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(User $user): array
    {
        return DashboardPanel::query()
            ->where('user_id', $user->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (DashboardPanel $panel): array => $this->toRenderArray($panel))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(User $user, int $limit = 20): array
    {
        return DashboardPanelVersion::query()
            ->where('user_id', $user->id)
            ->orderByDesc('version')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (DashboardPanelVersion $row): array => [
                'version' => (int) $row->version,
                'source' => (string) $row->source,
                'panel_count' => is_array($row->panels) ? count($row->panels) : 0,
                'created_at' => $row->created_at?->toIso8601String(),
                'created_by_user_id' => $row->created_by_user_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Replace a user's panel set after schema validation, snapshotting the prior set.
     *
     * @param  list<array<string, mixed>>  $panels
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function setForUser(User $user, array $panels, string $source = 'curator', ?User $actor = null): array
    {
        $validated = $this->schema->validateMany($panels);

        DB::transaction(function () use ($user, $validated, $source, $actor): void {
            $this->snapshotCurrent($user, $source, $actor);

            DashboardPanel::query()
                ->where('user_id', $user->id)
                ->delete();

            foreach ($validated as $index => $row) {
                DashboardPanel::query()->create([
                    'user_id' => $user->id,
                    'position' => $row['position'] ?? $index,
                    'type' => $row['type'],
                    'title' => $row['title'],
                    'payload' => $row['payload'],
                    'source' => $row['source'] !== '' ? $row['source'] : $source,
                ]);
            }
        });

        return $this->listForUser($user);
    }

    /**
     * Restore a prior panel set by version number.
     *
     * @return list<array<string, mixed>>
     */
    public function rollbackForUser(User $user, int $version, ?User $actor = null): array
    {
        $row = DashboardPanelVersion::query()
            ->where('user_id', $user->id)
            ->where('version', $version)
            ->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'version' => 'Panel version not found for this user.',
            ]);
        }

        $panels = is_array($row->panels) ? $row->panels : [];

        return $this->setForUser($user, $panels, 'rollback', $actor);
    }

    private function snapshotCurrent(User $user, string $source, ?User $actor): void
    {
        $current = $this->listForUser($user);
        if ($current === []) {
            return;
        }

        $nextVersion = ((int) DashboardPanelVersion::query()
            ->where('user_id', $user->id)
            ->max('version')) + 1;

        DashboardPanelVersion::query()->create([
            'user_id' => $user->id,
            'version' => $nextVersion,
            'panels' => array_map(fn (array $panel): array => [
                'position' => $panel['position'],
                'type' => $panel['type'],
                'title' => $panel['title'],
                'payload' => $panel['payload'],
                'source' => $panel['source'],
            ], $current),
            'source' => $source,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRenderArray(DashboardPanel $panel): array
    {
        return [
            'id' => (int) $panel->id,
            'position' => (int) $panel->position,
            'type' => (string) $panel->type,
            'title' => (string) $panel->title,
            'payload' => is_array($panel->payload) ? $panel->payload : [],
            'source' => (string) $panel->source,
        ];
    }
}
