<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\SocialAd;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAd>
 */
class SocialAdFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'platform' => Platform::Instagram,
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(10),
            'url' => 'https://www.facebook.com/ads/library/?id='.fake()->unique()->numerify('##########'),
            'thumbnail_url' => null,
            'is_active' => true,
            'last_seen_at' => now(),
            'raw' => [],
        ];
    }
}
