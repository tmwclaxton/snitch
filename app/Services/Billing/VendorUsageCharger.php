<?php

namespace App\Services\Billing;

use App\Enums\BillingVendor;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Apify\ApifyClient;

class VendorUsageCharger
{
    public function __construct(
        private UsageBillingService $billing,
        private ApifyClient $apify,
    ) {}

    public function assertCanRun(User $user, int $estimatedPence = 1): void
    {
        $this->billing->assertCanRun($user, $estimatedPence);
    }

    /**
     * Charge for all Apify runs recorded since the last pull.
     *
     * @return list<CreditLedgerEntry>
     */
    public function chargePulledApifyRuns(User $user, string $action = 'apify.run'): array
    {
        $entries = [];

        foreach ($this->apify->pullRunCosts() as $run) {
            $entries[] = $this->billing->charge(
                user: $user,
                action: $action,
                vendor: BillingVendor::Apify,
                cogsUsd: $run['usageTotalUsd'],
                meta: [
                    'actor_id' => $run['actorId'],
                    'run_id' => $run['runId'],
                ],
                idempotencyKey: $run['runId'] !== null ? 'apify:'.$run['runId'] : null,
            );
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function chargeNanoGpt(
        User $user,
        string $action,
        ?float $cogsUsd = null,
        array $meta = [],
        ?string $idempotencyKey = null,
    ): CreditLedgerEntry {
        return $this->billing->charge(
            user: $user,
            action: $action,
            vendor: BillingVendor::NanoGpt,
            cogsUsd: $cogsUsd,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function chargeFirecrawl(
        User $user,
        string $action,
        ?float $cogsUsd = null,
        array $meta = [],
        ?string $idempotencyKey = null,
    ): CreditLedgerEntry {
        return $this->billing->charge(
            user: $user,
            action: $action,
            vendor: BillingVendor::Firecrawl,
            cogsUsd: $cogsUsd,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
        );
    }
}
