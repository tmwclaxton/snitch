<?php

namespace App\Services\Scraping;

use App\Enums\BillingVendor;
use App\Enums\Platform;
use App\Models\CreditLedgerEntry;
use Illuminate\Support\Facades\Cache;

class ApifyMonthlyCapGate
{
    public function monthlyCapUsd(): float
    {
        return max(0.0, (float) config('snitch.apify.monthly_cap_usd', 49));
    }

    public function tikHubConfigured(): bool
    {
        return (string) config('snitch.tikhub.api_key') !== '';
    }

    /**
     * Platforms TikHub can serve when Apify is exhausted.
     */
    public function tikHubSupports(Platform|string $platform): bool
    {
        $platform = $platform instanceof Platform ? $platform : Platform::from($platform);

        return match ($platform) {
            Platform::Instagram, Platform::TikTok, Platform::Youtube, Platform::LinkedIn => true,
            Platform::Facebook => false,
        };
    }

    public function shouldUseTikHub(Platform|string $platform): bool
    {
        if (! $this->tikHubConfigured()) {
            return false;
        }

        if (! $this->tikHubSupports($platform)) {
            return false;
        }

        return $this->isApifyExhausted();
    }

    public function isApifyExhausted(): bool
    {
        if ($this->hardExhaustedCached()) {
            return true;
        }

        $cap = $this->monthlyCapUsd();

        if ($cap <= 0) {
            return false;
        }

        return $this->monthToDateApifyCogsUsd() >= $cap;
    }

    public function monthToDateApifyCogsUsd(): float
    {
        $sum = CreditLedgerEntry::query()
            ->where('vendor', BillingVendor::Apify)
            ->where('created_at', '>=', now()->utc()->startOfMonth())
            ->whereNotNull('cogs_usd')
            ->sum('cogs_usd');

        return max(0.0, (float) $sum);
    }

    public function remainingUsd(): ?float
    {
        $cap = $this->monthlyCapUsd();

        if ($cap <= 0) {
            return null;
        }

        return max(0.0, $cap - $this->monthToDateApifyCogsUsd());
    }

    public function markHardExhausted(): void
    {
        Cache::put($this->hardExhaustCacheKey(), true, now()->utc()->endOfMonth());
    }

    public function clearHardExhausted(): void
    {
        Cache::forget($this->hardExhaustCacheKey());
    }

    /**
     * Detect Apify payment / quota style failures from status + body.
     */
    public function looksLikeQuotaFailure(int $status, string $body): bool
    {
        if (in_array($status, [402, 403], true)) {
            $lower = strtolower($body);

            if ($status === 402) {
                return true;
            }

            return str_contains($lower, 'quota')
                || str_contains($lower, 'payment')
                || str_contains($lower, 'billing')
                || str_contains($lower, 'insufficient')
                || str_contains($lower, 'limit exceeded')
                || str_contains($lower, 'monthly usage');
        }

        $lower = strtolower($body);

        return str_contains($lower, 'monthly usage')
            || str_contains($lower, 'usage limit')
            || str_contains($lower, 'not enough usage');
    }

    private function hardExhaustedCached(): bool
    {
        return (bool) Cache::get($this->hardExhaustCacheKey(), false);
    }

    private function hardExhaustCacheKey(): string
    {
        return 'snitch:apify_hard_exhausted:'.now()->utc()->format('Y-m');
    }
}
