<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackedAccount>
 */
class TrackedAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $platform = fake()->randomElement(Platform::cases());
        $handle = fake()->unique()->userName();

        return [
            'user_id' => User::factory(),
            'platform' => $platform,
            'handle' => $handle,
            'url' => "https://{$platform->value}.com/{$handle}",
            'external_id' => (string) fake()->unique()->numerify('##########'),
            'avatar' => fake()->imageUrl(),
            'display_name' => fake()->name(),
            'last_synced_at' => null,
        ];
    }

    public function forPlatform(Platform $platform): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => $platform,
            'url' => "https://{$platform->value}.com/{$attributes['handle']}",
        ]);
    }
}
