<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @var \WeakMap<User, true>
     */
    private static \WeakMap $skipStarterCredit;

    /**
     * Claimed web users get the £5 starter credit, matching claim/signup.
     * Unclaimed MCP agents stay at £0 until claim.
     */
    public function configure(): static
    {
        self::$skipStarterCredit ??= new \WeakMap;

        return $this->afterCreating(function (User $user): void {
            if ($user->claimed_at === null || isset(self::$skipStarterCredit[$user])) {
                return;
            }

            app(UsageBillingService::class)->creditClaimBonus($user);
        });
    }

    /**
     * Skip the automatic £5 claim bonus (for isolated billing / expiry tests).
     */
    public function withoutStarterCredit(): static
    {
        self::$skipStarterCredit ??= new \WeakMap;

        return $this->afterMaking(function (User $user): void {
            self::$skipStarterCredit[$user] = true;
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'workos_id' => 'fake-'.Str::random(10),
            'remember_token' => Str::random(10),
            'avatar' => '',
            'created_via' => 'web',
            'claim_token' => null,
            'claimed_at' => now(),
        ];
    }

    public function unclaimedAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'workos_id' => null,
            'created_via' => 'mcp',
            'claim_token' => Str::random(48),
            'claimed_at' => null,
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Active app trial (Basic entitlements, no Stripe subscription).
     */
    public function onTrial(?int $days = 7): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_ends_at' => now()->addDays($days),
        ]);
    }

    /**
     * Expired trial / Free plan (no active subscription).
     */
    public function freePlan(): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_ends_at' => now()->subDay(),
        ]);
    }
}
