<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateInfluencerBriefJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(
        InfluencerDiscoveryService $discovery,
        VendorUsageCharger $charger,
        UsageBillingService $billing,
    ): void {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $brand = BrandProfile::query()
            ->where('user_id', $this->userId)
            ->first();

        if ($brand === null) {
            return;
        }

        if (trim((string) ($brand->influencer_brief ?? '')) !== '') {
            return;
        }

        try {
            $charger->assertCanRun($user);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $e) {
            Log::info('GenerateInfluencerBriefJob skipped: cannot run billable work', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $filters = [
            'platforms' => [(string) config('snitch.influencer_find.default_platform', 'instagram')],
            'language' => (string) config('snitch.influencer_find.default_language', 'English'),
            'min_followers' => (int) config('snitch.influencer_find.default_min_followers', 1000),
            'max_followers' => (int) config('snitch.influencer_find.default_max_followers', 50000),
        ];

        $brief = $discovery->generateBrief($brand, $filters);

        $brand->forceFill([
            'influencer_brief' => $brief,
        ])->save();

        $charger->chargeNanoGpt(
            user: $user,
            action: 'influencer.brief',
            cogsUsd: $billing->estimateNanoGptChatUsd(
                null,
                null,
                (string) config('snitch.influencer_find.model'),
            ),
            meta: ['kind' => 'onboarding'],
            idempotencyKey: 'influencer.brief:onboarding:'.$this->userId,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('GenerateInfluencerBriefJob failed', [
            'user_id' => $this->userId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
