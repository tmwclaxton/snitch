<?php

namespace App\Services\Billing;

use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform subscription helpers. Seat/competitor caps are retired in favour of
 * usage credits ({@see UsageBillingService}).
 */
class PlanEntitlementService
{
    public function __construct(private UsageBillingService $usage) {}

    public function hasPlatformSubscription(User $user): bool
    {
        return $this->usage->hasPlatformSubscription($user);
    }

    public function plan(User $user): string
    {
        return $this->hasPlatformSubscription($user) ? 'platform' : 'none';
    }

    public function canAddCompetitors(User $user, int $additional = 1): bool
    {
        return true;
    }

    public function canAddInfluencers(User $user, int $additional = 1): bool
    {
        return true;
    }

    /**
     * @return list<int>
     */
    public function inQuotaTrackedAccountIds(User $user): array
    {
        return $user->trackedAccounts()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function isTrackedAccountInQuota(User $user, TrackedAccount|int $account): bool
    {
        return true;
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function constrainPostsToInQuotaAccounts(Builder $query, User $user): Builder
    {
        return $query;
    }

    public function onTrial(User $user): bool
    {
        return false;
    }

    /**
     * @return array{
     *     plan: string,
     *     plan_name: string,
     *     competitor_limit: int|null,
     *     competitors_used: int,
     *     competitors_remaining: int|null,
     *     over_quota_competitors: int,
     *     influencer_limit: int|null,
     *     influencers_used: int,
     *     influencers_remaining: int|null,
     *     over_quota_influencers: int,
     *     on_trial: bool,
     *     trial_ends_at: string|null,
     *     subscribed: bool,
     *     billing_interval: string|null,
     *     can_upgrade: bool,
     *     balance_pence: float,
     *     min_run_balance_pence: int,
     *     can_run_billable: bool,
     *     platform_fee_pence: int,
     *     usage: array<string, mixed>
     * }
     */
    public function summary(User $user): array
    {
        $subscribed = $this->hasPlatformSubscription($user);
        $usage = $this->usage->summary($user);
        $competitorsUsed = $user->trackedAccounts()->competitors()->count();
        $influencersUsed = $user->trackedAccounts()->influencers()->count();

        return [
            'plan' => $subscribed ? 'platform' : 'none',
            'plan_name' => $subscribed ? 'Platform' : 'No plan',
            'competitor_limit' => null,
            'competitors_used' => $competitorsUsed,
            'competitors_remaining' => null,
            'over_quota_competitors' => 0,
            'influencer_limit' => null,
            'influencers_used' => $influencersUsed,
            'influencers_remaining' => null,
            'over_quota_influencers' => 0,
            'on_trial' => false,
            'trial_ends_at' => null,
            'subscribed' => $subscribed,
            'billing_interval' => $subscribed ? 'month' : null,
            'can_upgrade' => ! $subscribed,
            'balance_pence' => $usage['balance_pence'],
            'min_run_balance_pence' => $this->usage->minRunBalancePence(),
            'can_run_billable' => $this->usage->canRun($user),
            'platform_fee_pence' => $usage['platform_fee_pence'],
            'usage' => $usage,
        ];
    }

    public function ensureTrialStarted(User $user): void
    {
        // Seat trials retired. Claim bonus is granted on account claim/confirm.
    }

    public function platformStripePriceId(): ?string
    {
        $price = config('billing.platform_stripe_price');

        return is_string($price) && $price !== '' ? $price : null;
    }

    public function stripePriceIdForPlan(string $plan, string $interval = 'month'): ?string
    {
        if ($plan === 'platform' && $interval === 'month') {
            return $this->platformStripePriceId();
        }

        return null;
    }

    public function creditPackStripePriceId(string $packKey): ?string
    {
        $price = config("billing.credit_packs.{$packKey}.stripe_price");

        return is_string($price) && $price !== '' ? $price : null;
    }
}
