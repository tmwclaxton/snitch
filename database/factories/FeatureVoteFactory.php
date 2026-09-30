<?php

namespace Database\Factories;

use App\Models\FeatureSuggestion;
use App\Models\FeatureVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureVote>
 */
class FeatureVoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'suggestion_id' => FeatureSuggestion::factory(),
        ];
    }
}
