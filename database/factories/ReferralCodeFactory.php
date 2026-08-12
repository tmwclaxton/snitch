<?php

namespace Database\Factories;

use App\Models\ReferralCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReferralCode>
 */
class ReferralCodeFactory extends Factory
{
    protected $model = ReferralCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::lower(Str::random(8));

        return [
            'code' => $slug,
            'name' => fake()->company().' partner',
            'notes' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
