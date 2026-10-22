<?php

namespace Database\Factories;

use App\Models\DashboardPanel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardPanel>
 */
class DashboardPanelFactory extends Factory
{
    protected $model = DashboardPanel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'position' => 0,
            'type' => DashboardPanel::TYPE_BIG_NUMBER,
            'title' => 'Followers',
            'payload' => [
                'label' => 'Followers',
                'value' => '1,200',
                'note' => null,
            ],
            'source' => 'test',
        ];
    }

    public function note(string $body = 'Post a Reel tonight.'): static
    {
        return $this->state(fn (): array => [
            'type' => DashboardPanel::TYPE_NOTE,
            'title' => 'Today',
            'payload' => [
                'body' => $body,
                'action_label' => 'Open Today',
                'action_href' => '/today',
            ],
        ]);
    }
}
