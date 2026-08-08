<?php

namespace App\Services\Billing;

use App\Models\User;

class PlanEntitlementService
{
    /**
     * Resolve the effective plan key: free, basic, or pro.
     *
     * Active Basic/Pro subscriptions win. Otherwise an open app trial grants
     * Basic entitlements. Expired trial with no subscription is Free.
     */
    public function plan(User $user): string
    {
        $subscribed = $this->subscribedPlan($user);

        if ($subscribed !== null) {
            return $subscribed;
        }

        if ($user->onGenericTrial()) {
            return 'basic';
        }

        return 'free';
    }

    public function competitorLimit(User $user): int
    {
        $plan = $this->plan($user);

        return (int) config("subscriptions.plans.{$plan}.competitor_limit", 3);
    }

    public function competitorsUsed(User $user): int
    {
        return $user->trackedAccounts()->count();
    }

    public function competitorsRemaining(User $user): int
    {
        return max(0, $this->competitorLimit($user) - $this->competitorsUsed($user));
    }

    public function canAddCompetitors(User $user, int $additional = 1): bool
    {
        if ($additional <= 0) {
            return true;
        }

        return $this->competitorsRemaining($user) >= $additional;
    }

    public function onTrial(User $user): bool
    {
        return $this->subscribedPlan($user) === null && $user->onGenericTrial();
    }

    /**
     * @return array{
     *     plan: string,
     *     plan_name: string,
     *     competitor_limit: int,
     *     competitors_used: int,
     *     competitors_remaining: int,
     *     on_trial: bool,
     *     trial_ends_at: string|null,
     *     subscribed: bool,
     *     can_upgrade: bool
     * }
     */
    public function summary(User $user): array
    {
        $plan = $this->plan($user);
        $limit = $this->competitorLimit($user);
        $used = $this->competitorsUsed($user);
        $subscribed = $this->subscribedPlan($user) !== null;
        $onTrial = $this->onTrial($user);

        return [
            'plan' => $plan,
            'plan_name' => (string) config("subscriptions.plans.{$plan}.name", ucfirst($plan)),
            'competitor_limit' => $limit,
            'competitors_used' => $used,
            'competitors_remaining' => max(0, $limit - $used),
            'on_trial' => $onTrial,
            'trial_ends_at' => $user->trial_ends_at?->toIso8601String(),
            'subscribed' => $subscribed,
            'can_upgrade' => $plan !== 'pro',
        ];
    }

    public function ensureTrialStarted(User $user): void
    {
        if ($user->trial_ends_at !== null) {
            return;
        }

        $days = max(1, (int) config('subscriptions.trial_days', 7));

        $user->forceFill([
            'trial_ends_at' => now()->addDays($days),
        ])->save();
    }

    public function stripePriceIdForPlan(string $plan): ?string
    {
        $price = config("subscriptions.plans.{$plan}.stripe_price");

        return is_string($price) && $price !== '' ? $price : null;
    }

    public function planKeyForStripePrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        foreach (['basic', 'pro'] as $plan) {
            if ($this->stripePriceIdForPlan($plan) === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    private function subscribedPlan(User $user): ?string
    {
        $type = (string) config('subscriptions.subscription_type', 'default');
        $subscription = $user->subscription($type);

        if ($subscription === null || ! $subscription->valid()) {
            return null;
        }

        return $this->planKeyForStripePrice($subscription->stripe_price);
    }
}
