<?php

namespace App\Services\Billing;

use App\Enums\BillingVendor;
use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\CreditLedgerEntry;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;

class ExploreBillingService
{
    public const ACTION_SEARCH = 'explore.search';

    public const ACTION_VIEW = 'explore.view';

    public function __construct(private UsageBillingService $billing) {}

    /**
     * Proportional search fee: 0p when resultCount is 0, linear up to max_pence (default 0.5p
     * at results_for_max_pence, default 24). Same normalised query is idempotent.
     *
     * @param  'q'|'custom_tag'  $kind
     */
    public function chargeSearch(User $user, string $query, string $kind, int $resultCount): ?CreditLedgerEntry
    {
        $amountPence = $this->searchChargePence($resultCount);

        if ($amountPence === 0.0) {
            return null;
        }

        $normalized = mb_strtolower(trim($query));
        $idempotencyKey = sprintf(
            'explore.search:%d:%s:%s',
            $user->id,
            $kind,
            hash('sha256', $normalized),
        );

        return $this->billing->charge(
            user: $user,
            action: self::ACTION_SEARCH,
            vendor: BillingVendor::Snitch,
            cogsUsd: null,
            meta: [
                'query' => $normalized,
                'kind' => $kind,
                'result_count' => max(0, $resultCount),
            ],
            idempotencyKey: $idempotencyKey,
            amountPenceOverride: $amountPence,
        );
    }

    public function searchChargePence(int $resultCount): float
    {
        if ($resultCount <= 0) {
            return 0.0;
        }

        $action = config('billing.actions.explore.search', []);
        $maxPence = is_array($action) && isset($action['max_pence']) && is_numeric($action['max_pence'])
            ? max(0.0, (float) $action['max_pence'])
            : 0.5;
        $fullAt = is_array($action) && isset($action['results_for_max_pence']) && is_numeric($action['results_for_max_pence'])
            ? max(1, (int) $action['results_for_max_pence'])
            : 24;

        $scaled = ($resultCount / $fullAt) * $maxPence;

        return $this->roundSearchPence(min($maxPence, $scaled));
    }

    private function roundSearchPence(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Flat 0.1p view fee for reels whose author is not a tracked competitor.
     * Idempotent per user+post (`explore.view:{user_id}:{post_id}`).
     */
    public function chargeViewIfNeeded(User $user, Post $post): ?CreditLedgerEntry
    {
        if ($this->isTrackedCompetitorReel($user, $post)) {
            return null;
        }

        $idempotencyKey = sprintf('explore.view:%d:%d', $user->id, $post->id);

        $social = $post->relationLoaded('socialAccount')
            ? $post->socialAccount
            : $post->socialAccount()->first();

        $platform = $this->platformValue($post->platform);
        $handle = $social !== null ? $this->normalizeHandle($social->handle) : null;

        return $this->billing->charge(
            user: $user,
            action: self::ACTION_VIEW,
            vendor: BillingVendor::Snitch,
            cogsUsd: null,
            meta: array_filter([
                'post_id' => $post->id,
                'social_account_id' => $post->social_account_id,
                'platform' => $platform,
                'post_type' => $post->type?->value ?? (is_string($post->type) ? $post->type : null),
                'handle' => $handle,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Free when the reel's social account is one of the user's tracked competitors.
     */
    public function isTrackedCompetitorReel(User $user, Post $post): bool
    {
        if ($post->social_account_id === null) {
            return false;
        }

        return TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('kind', TrackedAccountKind::Competitor)
            ->where('social_account_id', $post->social_account_id)
            ->exists();
    }

    private function platformValue(mixed $platform): ?string
    {
        if ($platform instanceof Platform) {
            return $platform->value;
        }

        if (is_string($platform) && $platform !== '') {
            return strtolower($platform);
        }

        return null;
    }

    private function normalizeHandle(mixed $handle): ?string
    {
        if (! is_string($handle) && ! is_numeric($handle)) {
            return null;
        }

        $normalized = mb_strtolower(ltrim(trim((string) $handle), '@'));

        return $normalized === '' ? null : $normalized;
    }
}
