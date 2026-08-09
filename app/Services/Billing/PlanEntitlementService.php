<?php

namespace App\Services\Billing;

use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Oldest tracked accounts keep plan slots; newer excess rows stay listed but are over-quota.
     *
     * @return list<int>
     */
    public function inQuotaTrackedAccountIds(User $user): array
    {
        $limit = $this->competitorLimit($user);

        if ($limit <= 0) {
            return [];
        }

        return $user->trackedAccounts()
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function isTrackedAccountInQuota(User $user, TrackedAccount|int $account): bool
    {
        $accountId = $account instanceof TrackedAccount ? (int) $account->id : $account;

        return in_array($accountId, $this->inQuotaTrackedAccountIds($user), true);
    }

    /**
     * Hide posts from over-quota tracked accounts on product surfaces.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function constrainPostsToInQuotaAccounts(Builder $query, User $user): Builder
    {
        $ids = $this->inQuotaTrackedAccountIds($user);

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('tracked_account_id', $ids);
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
     *     over_quota_competitors: int,
     *     on_trial: bool,
     *     trial_ends_at: string|null,
     *     subscribed: bool,
     *     billing_interval: string|null,
     *     can_upgrade: bool
     * }
     */
    public function summary(User $user): array
    {
        $plan = $this->plan($user);
        $limit = $this->competitorLimit($user);
        $used = $this->competitorsUsed($user);
        $priceId = $this->subscribedStripePriceId($user);
        $subscribed = $priceId !== null;
        $onTrial = $this->onTrial($user);

        return [
            'plan' => $plan,
            'plan_name' => (string) config("subscriptions.plans.{$plan}.name", ucfirst($plan)),
            'competitor_limit' => $limit,
            'competitors_used' => $used,
            'competitors_remaining' => max(0, $limit - $used),
            'over_quota_competitors' => max(0, $used - $limit),
            'on_trial' => $onTrial,
            'trial_ends_at' => $user->trial_ends_at?->toIso8601String(),
            'subscribed' => $subscribed,
            'billing_interval' => $subscribed ? $this->billingIntervalForStripePrice($priceId) : null,
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

    public function stripePriceIdForPlan(string $plan, string $interval = 'month'): ?string
    {
        $configKey = $interval === 'year' ? 'stripe_price_yearly' : 'stripe_price';
        $price = config("subscriptions.plans.{$plan}.{$configKey}");

        return is_string($price) && $price !== '' ? $price : null;
    }

    public function planKeyForStripePrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        foreach (['basic', 'pro'] as $plan) {
            if ($this->stripePriceIdForPlan($plan, 'month') === $priceId) {
                return $plan;
            }

            if ($this->stripePriceIdForPlan($plan, 'year') === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    public function billingIntervalForStripePrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        foreach (['basic', 'pro'] as $plan) {
            if ($this->stripePriceIdForPlan($plan, 'month') === $priceId) {
                return 'month';
            }

            if ($this->stripePriceIdForPlan($plan, 'year') === $priceId) {
                return 'year';
            }
        }

        return null;
    }

    private function subscribedPlan(User $user): ?string
    {
        $priceId = $this->subscribedStripePriceId($user);

        return $priceId === null ? null : $this->planKeyForStripePrice($priceId);
    }

    private function subscribedStripePriceId(User $user): ?string
    {
        $type = (string) config('subscriptions.subscription_type', 'default');
        $subscription = $user->subscription($type);

        if ($subscription === null || ! $subscription->valid()) {
            return null;
        }

        $priceId = $subscription->stripe_price;

        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }
}
