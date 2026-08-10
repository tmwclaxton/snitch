<?php

namespace App\Services\Billing;

use App\Enums\BillingVendor;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Apify\ApifyClient;
use App\Services\TikHub\TikHubClient;

class VendorUsageCharger
{
    public function __construct(
        private UsageBillingService $billing,
        private ApifyClient $apify,
        private TikHubClient $tikhub,
    ) {}

    public function assertCanRun(User $user, float $estimatedPence = 1): void
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
     * Charge for TikHub calls recorded since the last pull.
     *
     * @return list<CreditLedgerEntry>
     */
    public function chargePulledTikHubRuns(User $user, string $action = 'sync.account'): array
    {
        $entries = [];

        foreach ($this->tikhub->pullRunCosts() as $run) {
            $entries[] = $this->billing->charge(
                user: $user,
                action: $action,
                vendor: BillingVendor::TikHub,
                cogsUsd: $run['cogsUsd'],
                meta: [
                    'endpoint' => $run['endpoint'],
                    'platform' => $run['platform'],
                ],
            );
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function chargeTikHub(
        User $user,
        string $action,
        ?float $cogsUsd = null,
        array $meta = [],
        ?string $idempotencyKey = null,
    ): CreditLedgerEntry {
        return $this->billing->charge(
            user: $user,
            action: $action,
            vendor: BillingVendor::TikHub,
            cogsUsd: $cogsUsd,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
        );
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
