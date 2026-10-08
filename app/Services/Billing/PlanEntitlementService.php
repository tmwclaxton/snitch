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
    /**
     * Per-request memoization of the shared Inertia subscription payload.
     * HandleInertiaRequests runs once per HTTP request but this guards against
     * any future double-resolution (e.g. exception handler share fallback).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $sharedSummaryCache = [];

    public function __construct(private UsageBillingService $usage) {}

    public function forgetSharedSummary(?User $user = null): void
    {
        if ($user === null) {
            $this->sharedSummaryCache = [];

            return;
        }

        unset($this->sharedSummaryCache[$user->id]);
    }

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
        if ($this->usage->hasOperatorBypass($user)) {
            return true;
        }

        $limit = $this->competitorLimitFor($user);

        if ($limit === null) {
            return true;
        }

        return $user->trackedAccounts()->competitors()->count() + $additional <= $limit;
    }

    public function competitorLimitFor(User $user): ?int
    {
        $trialLimit = max(0, (int) config('subscriptions.trial_competitor_limit', 3));

        if (! $this->hasPlatformSubscription($user)) {
            return $trialLimit > 0 ? $trialLimit : null;
        }

        $type = (string) config('billing.subscription_type', 'default');
        $subscription = $user->subscription($type);

        if ($subscription !== null && $subscription->onTrial()) {
            return $trialLimit > 0 ? $trialLimit : null;
        }

        return null;
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

    public function trialDays(): int
    {
        return $this->usage->trialDays();
    }

    public function onTrial(User $user): bool
    {
        return $this->usage->isOnWebTrial($user);
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
     *     paywall: array{blocked: bool, reason: string|null, message: string|null, starter_allowance_exhausted: bool, can_top_up: bool},
     *     usage: array<string, mixed>
     * }
     */
    public function summary(User $user): array
    {
        $subscribed = $this->hasPlatformSubscription($user);
        $operator = $this->usage->hasOperatorBypass($user);
        $usage = $this->usage->summary($user);
        $paywall = $this->usage->paywallState($user);
        $type = (string) config('billing.subscription_type', 'default');
        $subscription = $user->subscription($type);
        $onStripeTrial = $subscription !== null && $subscription->onTrial();
        $onTrial = ! $operator && ($onStripeTrial || $this->onTrial($user));
        $competitorLimit = $this->competitorLimitFor($user);
        $competitorsUsed = $paywall['blocked']
            ? 0
            : $user->trackedAccounts()->competitors()->count();
        $influencersUsed = $paywall['blocked']
            ? 0
            : $user->trackedAccounts()->influencers()->count();
        $competitorsRemaining = $competitorLimit === null
            ? null
            : max(0, $competitorLimit - $competitorsUsed);

        return [
            'plan' => $operator ? 'admin' : ($subscribed ? 'platform' : 'none'),
            'plan_name' => $operator ? 'Admin' : ($subscribed ? 'Platform' : 'No plan'),
            'competitor_limit' => $competitorLimit,
            'competitors_used' => $competitorsUsed,
            'competitors_remaining' => $competitorsRemaining,
            'over_quota_competitors' => $competitorLimit === null
                ? 0
                : max(0, $competitorsUsed - $competitorLimit),
            'influencer_limit' => null,
            'influencers_used' => $influencersUsed,
            'influencers_remaining' => null,
            'over_quota_influencers' => 0,
            'on_trial' => $onTrial,
            'trial_ends_at' => $onTrial
                ? ($onStripeTrial ? $subscription?->trial_ends_at?->toIso8601String() : $user->trial_ends_at?->toIso8601String())
                : null,
            'subscribed' => $subscribed,
            'billing_interval' => $subscribed ? 'month' : null,
            'can_upgrade' => ! $operator && ! $subscribed,
            'balance_pence' => $usage['balance_pence'],
            'min_run_balance_pence' => $this->usage->minRunBalancePence(),
            'can_run_billable' => ! $paywall['blocked'],
            'platform_fee_pence' => $usage['platform_fee_pence'],
            'paywall' => $paywall,
            'usage' => $usage,
        ];
    }

    /**
     * Lightweight subscription payload for shared Inertia props. Skips the
     * heavy ledger aggregates in {@see summary()} (period vendor rollup,
     * all-time spend sum, recent-8 preview) which shared props never consume.
     *
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
     *     paywall: array{blocked: bool, reason: string|null, message: string|null, starter_allowance_exhausted: bool, can_top_up: bool}
     * }
     */
    public function sharedSummary(User $user): array
    {
        if (isset($this->sharedSummaryCache[$user->id])) {
            return $this->sharedSummaryCache[$user->id];
        }

        $subscribed = $this->hasPlatformSubscription($user);
        $operator = $this->usage->hasOperatorBypass($user);
        $paywall = $this->usage->paywallState($user);
        $type = (string) config('billing.subscription_type', 'default');
        $subscription = $user->subscription($type);
        $onStripeTrial = $subscription !== null && $subscription->onTrial();
        $onTrial = ! $operator && ($onStripeTrial || $this->onTrial($user));
        $competitorLimit = $this->competitorLimitFor($user);
        $balancePence = $this->usage->balancePence($user);
        $minRunBalancePence = $this->usage->minRunBalancePence();
        $competitorsUsed = $paywall['blocked']
            ? 0
            : $user->trackedAccounts()->competitors()->count();
        $influencersUsed = $paywall['blocked']
            ? 0
            : $user->trackedAccounts()->influencers()->count();
        $competitorsRemaining = $competitorLimit === null
            ? null
            : max(0, $competitorLimit - $competitorsUsed);
        $trialEndsAt = $onTrial
            ? ($onStripeTrial
                ? $subscription?->trial_ends_at?->toIso8601String()
                : $user->trial_ends_at?->toIso8601String())
            : null;

        return $this->sharedSummaryCache[$user->id] = [
            'plan' => $operator ? 'admin' : ($subscribed ? 'platform' : 'none'),
            'plan_name' => $operator ? 'Admin' : ($subscribed ? 'Platform' : 'No plan'),
            'competitor_limit' => $competitorLimit,
            'competitors_used' => $competitorsUsed,
            'competitors_remaining' => $competitorsRemaining,
            'over_quota_competitors' => $competitorLimit === null
                ? 0
                : max(0, $competitorsUsed - $competitorLimit),
            'influencer_limit' => null,
            'influencers_used' => $influencersUsed,
            'influencers_remaining' => null,
            'over_quota_influencers' => 0,
            'on_trial' => $onTrial,
            'trial_ends_at' => $trialEndsAt,
            'subscribed' => $subscribed,
            'billing_interval' => $subscribed ? 'month' : null,
            'can_upgrade' => ! $operator && ! $subscribed,
            'balance_pence' => $balancePence,
            'min_run_balance_pence' => $minRunBalancePence,
            'can_run_billable' => ! $paywall['blocked'],
            'platform_fee_pence' => (int) config('billing.platform_fee_pence', 1900),
            'paywall' => $paywall,
        ];
    }

    public function ensureTrialStarted(User $user): void
    {
        $this->usage->ensureClaimedEntitlements($user);
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
