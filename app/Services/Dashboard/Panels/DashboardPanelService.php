<?php

namespace App\Services\Dashboard\Panels;

use App\Models\DashboardPanel;
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
     * Replace a user's panel set after schema validation.
     *
     * @param  list<array<string, mixed>>  $panels
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function setForUser(User $user, array $panels, string $source = 'curator'): array
    {
        $validated = $this->schema->validateMany($panels);

        DB::transaction(function () use ($user, $validated, $source): void {
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
