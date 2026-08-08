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
            'url' => $this->profileUrl($platform, $handle),
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
            'url' => $this->profileUrl($platform, (string) $attributes['handle']),
        ]);
    }

    private function profileUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => "https://instagram.com/{$handle}",
            Platform::TikTok => "https://tiktok.com/@{$handle}",
            Platform::Facebook => "https://facebook.com/{$handle}",
            Platform::LinkedIn => "https://linkedin.com/company/{$handle}",
            Platform::Youtube => "https://youtube.com/@{$handle}",
        };
    }
}
