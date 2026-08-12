<script setup lang="ts">
import { computed } from 'vue';
import { formatPenceAsGbp } from '@/lib/money';

export type CreditExpiryLot = {
    action: string;
    label: string;
    remaining_pence: number;
};

export type CreditExpiryBucket = {
    expires_at: string | null;
    expires_label: string;
    remaining_pence: number;
    lots: CreditExpiryLot[];
};

export type CreditExpiryBreakdown = {
    total_remaining_pence: number;
    topup_expiry_months: number;
    buckets: CreditExpiryBucket[];
};

const props = withDefaults(
    defineProps<{
        breakdown: CreditExpiryBreakdown;
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const hasBuckets = computed(() => props.breakdown.buckets.length > 0);

function formatMoney(pence: number): string {
    return formatPenceAsGbp(pence);
}
</script>

<template>
    <section
        class="snitch-scrap relative space-y-3 p-5 pt-6 sm:p-6"
        data-test="credit-expiry-breakdown"
    >
        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="snitch-display text-2xl text-snitch-ink">
                {{ compact ? 'Credit expiry' : 'When your credit expires' }}
            </h2>
            <p
                v-if="hasBuckets"
                class="text-xs uppercase tracking-wide text-snitch-ink/45"
            >
                {{ formatMoney(breakdown.total_remaining_pence) }} unexpired
            </p>
        </div>
        <p class="text-sm text-snitch-ink/70">
            Starter credit never expires. Plan credits expire at month end.
            Top-ups expire {{ breakdown.topup_expiry_months }} months after purchase.
            Usage spends soonest-expiring credit first.
        </p>

        <ul
            v-if="hasBuckets"
            class="divide-y divide-snitch-ink/10 text-sm"
        >
            <li
                v-for="bucket in breakdown.buckets"
                :key="bucket.expires_at ?? 'never'"
                class="py-2.5"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium text-snitch-ink">
                            {{ bucket.expires_label }}
                        </p>
                        <ul
                            v-if="!compact && bucket.lots.length > 1"
                            class="mt-1 space-y-0.5 text-xs text-snitch-ink/60"
                        >
                            <li
                                v-for="lot in bucket.lots"
                                :key="`${bucket.expires_at}-${lot.action}-${lot.remaining_pence}`"
                            >
                                {{ lot.label }} · {{ formatMoney(lot.remaining_pence) }}
                            </li>
                        </ul>
                        <p
                            v-else-if="bucket.lots.length === 1"
                            class="mt-0.5 text-xs text-snitch-ink/60"
                        >
                            {{ bucket.lots[0].label }}
                        </p>
                    </div>
                    <span class="shrink-0 tabular-nums text-snitch-ink">
                        {{ formatMoney(bucket.remaining_pence) }}
                    </span>
                </div>
            </li>
        </ul>
        <p
            v-else
            class="text-sm text-snitch-ink/60"
        >
            No unexpired credit lots right now.
        </p>
    </section>
</template>
