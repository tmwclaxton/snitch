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
     * Flat 0.5p search fee. Same normalised query is idempotent.
     *
     * @param  'q'|'custom_tag'  $kind
     */
    public function chargeSearch(User $user, string $query, string $kind = 'q'): CreditLedgerEntry
    {
        $normalized = mb_strtolower(trim($query));
        $idempotencyKey = sprintf(
            'explore.search:%d:%s:%s',
            $user->id,
            $kind,
            hash('sha256', $normalized),
        );

        $entry = $this->billing->charge(
            user: $user,
            action: self::ACTION_SEARCH,
            vendor: BillingVendor::Snitch,
            cogsUsd: null,
            meta: [
                'query' => $normalized,
                'kind' => $kind,
            ],
            idempotencyKey: $idempotencyKey,
        );

        if ($entry === null) {
            throw new \RuntimeException('Explore search fee must not round to zero.');
        }

        return $entry;
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
