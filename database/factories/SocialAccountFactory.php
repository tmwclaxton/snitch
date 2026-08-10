<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $platform = fake()->randomElement(Platform::cases());
        $handle = fake()->unique()->userName();

        return [
            'platform' => $platform,
            'handle' => mb_strtolower($handle),
            'url' => match ($platform) {
                Platform::Instagram => "https://instagram.com/{$handle}",
                Platform::TikTok => "https://tiktok.com/@{$handle}",
                Platform::Facebook => "https://facebook.com/{$handle}",
                Platform::LinkedIn => "https://linkedin.com/company/{$handle}",
                Platform::Youtube => "https://youtube.com/@{$handle}",
            },
            'external_id' => (string) fake()->unique()->numerify('##########'),
            'avatar' => fake()->imageUrl(),
            'display_name' => fake()->name(),
        ];
    }

    public function forPlatform(Platform $platform): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => $platform,
            'url' => match ($platform) {
                Platform::Instagram => 'https://instagram.com/'.$attributes['handle'],
                Platform::TikTok => 'https://tiktok.com/@'.$attributes['handle'],
                Platform::Facebook => 'https://facebook.com/'.$attributes['handle'],
                Platform::LinkedIn => 'https://linkedin.com/company/'.$attributes['handle'],
                Platform::Youtube => 'https://youtube.com/@'.$attributes['handle'],
            },
        ]);
    }
}
