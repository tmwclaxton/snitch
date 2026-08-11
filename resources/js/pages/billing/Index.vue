<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { CreditCard, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import BillingController, {
    charges as billingCharges,
    index as billingIndex,
} from '@/actions/App/Http/Controllers/Settings/BillingController';
import VendorSpendStackedChart from '@/components/billing/VendorSpendStackedChart.vue';
import type {
    SpendGrain,
    SpendPoint,
} from '@/components/billing/VendorSpendStackedChart.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { chargeLinkHref } from '@/lib/billingCharges';
import type { ChargeRow } from '@/lib/billingCharges';
import { formatPenceAsGbp } from '@/lib/money';
import {
    SPEND_VENDORS,
    VENDOR_ACCENT_BORDER,
    vendorIconSrc,
    vendorLabel,
} from '@/lib/vendors';
import type { SpendVendorKey } from '@/lib/vendors';
import { pricing } from '@/routes';

type VendorUsage = {
    spend_pence: number;
    entries: number;
};

type UsageSummary = {
    balance_pence: number;
    subscribed: boolean;
    platform_fee_pence: number;
    period_spend_pence: number;
    all_time_spend_pence: number;
    vendors: Record<string, VendorUsage>;
    recent: ChargeRow[];
    recent_total: number;
    recent_has_more: boolean;
};

type SubscriptionSummary = {
    plan: string;
    plan_name: string;
    subscribed: boolean;
    balance_pence: number;
    platform_fee_pence: number;
    competitors_used: number;
    influencers_used: number;
};

type CreditPack = {
    key: string;
    name: string;
    credits_pence: number;
    price_pence: number;
    has_checkout: boolean;
};

type SpendSeries = {
    grain: SpendGrain;
    period_count: number;
    days: number;
    from: string;
    to: string;
    points: SpendPoint[];
};

const props = defineProps<{
    subscription: SubscriptionSummary;
    usage: UsageSummary;
    spendSeries?: SpendSeries | null;
    creditPacks: CreditPack[];
    platform: { fee_pence: number; bonus_pence: number; has_checkout: boolean };
}>();

const spendSeriesLoaded = computed(() => props.spendSeries != null);

defineOptions({
    layout: AppLayout,
});

const page = usePage();

const grainOptions: Array<{ value: SpendGrain; label: string }> = [
    { value: 'day', label: 'Daily' },
    { value: 'week', label: 'Weekly' },
    { value: 'month', label: 'Monthly' },
];

const selectedGrain = computed<SpendGrain>(() => props.spendSeries?.grain ?? 'day');

function grainHref(grain: SpendGrain): string {
    return billingIndex.url({
        query: grain === 'day' ? {} : { grain },
    });
}

const checkoutStatus = computed(() => {
    if (page.url.includes('checkout=success') || page.url.includes('checkout=credits_success')) {
        return 'success';
    }

    if (page.url.includes('checkout=cancelled')) {
        return 'cancelled';
    }

    return null;
});

function formatMoney(pence: number): string {
    return formatPenceAsGbp(pence);
}

function formatCatalogMoney(pence: number): string {
    return formatPenceAsGbp(pence, { decimals: 2 });
}

function rowLinkHref(row: ChargeRow): string {
    return chargeLinkHref(row.link) ?? '';
}

const spendVendors = SPEND_VENDORS;

function vendorAccent(key: SpendVendorKey): string {
    return VENDOR_ACCENT_BORDER[key];
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Billing" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <header class="flex flex-wrap items-end justify-between gap-4 border-b border-snitch-ink/10 pb-5">
                <div class="min-w-0">
                    <p class="snitch-ink-label">Account</p>
                    <h1 class="snitch-display mt-1 text-3xl text-snitch-ink sm:text-4xl">
                        Billing
                    </h1>
                    <p class="mt-1.5 max-w-2xl text-sm text-snitch-ink/65 sm:text-base">
                        Keep more than 20p of usage credit to run sync, analysis, and discovery. After
                        your free £5 starter, a paid plan is required. Plan credits expire at month end;
                        top-ups expire after 3 months; starter credit never expires.
                    </p>
                </div>
                <Link
                    :href="pricing()"
                    class="text-sm underline decoration-snitch-spot underline-offset-4"
                >
                    View public pricing
                </Link>
            </header>

            <p
                v-if="checkoutStatus === 'success'"
                class="mt-6 rounded-sm border border-snitch-ink/15 bg-snitch-spot/20 px-3 py-2 text-sm"
            >
                Checkout completed. Credits and plan status update shortly after Stripe confirms.
            </p>
            <p
                v-else-if="checkoutStatus === 'cancelled'"
                class="mt-6 rounded-sm border border-snitch-ink/15 px-3 py-2 text-sm"
            >
                Checkout cancelled. No charge was made.
            </p>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <section class="snitch-scrap relative space-y-4 p-5 pt-6 sm:p-6">
                    <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                    <h2 class="snitch-display text-2xl text-snitch-ink">Balance</h2>
                    <p class="snitch-display text-4xl text-snitch-ink sm:text-5xl">
                        {{ formatMoney(usage.balance_pence) }}
                    </p>
                    <p class="text-sm text-snitch-ink/70">
                        Platform plan:
                        <strong>{{ subscription.subscribed ? 'Active' : 'Not subscribed' }}</strong>
                        · {{ formatCatalogMoney(platform.fee_pence) }}/mo includes
                        {{ formatCatalogMoney(platform.bonus_pence) }} usage each billing period
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Form
                            v-if="!subscription.subscribed && platform.has_checkout"
                            v-bind="BillingController.checkout.form()"
                            class="inline"
                        >
                            <input type="hidden" name="product" value="platform" />
                            <button type="submit" class="snitch-btn" data-test="subscribe-platform">
                                <span class="flex items-center gap-2">
                                    <CreditCard class="size-4" />
                                    Subscribe
                                    <LoaderCircle class="size-4 animate-spin opacity-0 [[data-loading]_&]:opacity-100" />
                                </span>
                            </button>
                        </Form>
                        <Form v-bind="BillingController.portal.form()" class="inline">
                            <button type="submit" class="snitch-btn" data-test="billing-portal">
                                Stripe portal
                            </button>
                        </Form>
                    </div>
                </section>

                <section class="snitch-scrap relative space-y-4 p-5 pt-6 sm:p-6">
                    <span class="snitch-tape right-4 -top-2" aria-hidden="true" />
                    <h2 class="snitch-display text-2xl text-snitch-ink">Usage this period</h2>
                    <p class="text-sm text-snitch-ink/70">
                        Charged {{ formatMoney(usage.period_spend_pence) }} this month ·
                        {{ formatMoney(usage.all_time_spend_pence) }} all time
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div
                            v-for="key in spendVendors"
                            :key="key"
                            class="border border-snitch-ink/10 border-l-4 bg-snitch-paper/60 p-3"
                            :class="vendorAccent(key)"
                        >
                            <p class="snitch-ink-label inline-flex items-center gap-1.5">
                                <img
                                    :src="vendorIconSrc(key)"
                                    alt=""
                                    class="snitch-platform-logo size-3.5 shrink-0 object-contain"
                                    width="14"
                                    height="14"
                                >
                                {{ vendorLabel(key) }}
                            </p>
                            <p class="snitch-display text-2xl text-snitch-ink">
                                {{ formatMoney(usage.vendors[key]?.spend_pence ?? 0) }}
                            </p>
                            <p class="text-xs text-snitch-ink/60">
                                {{ usage.vendors[key]?.entries ?? 0 }} runs
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <section class="snitch-scrap relative mt-4 p-5 pt-6 sm:p-6">
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                <div
                    class="snitch-seg mb-4 flex flex-wrap gap-1"
                    role="group"
                    aria-label="Spend period"
                >
                    <Link
                        v-for="option in grainOptions"
                        :key="option.value"
                        :href="grainHref(option.value)"
                        class="snitch-seg-item px-3 py-1.5 text-sm"
                        :class="selectedGrain === option.value ? 'snitch-seg-item-active' : ''"
                        :aria-current="selectedGrain === option.value ? 'true' : undefined"
                        preserve-scroll
                        preserve-state
                    >
                        {{ option.label }}
                    </Link>
                </div>
                <SnitchSkeleton
                    v-if="!spendSeriesLoaded"
                    variant="scrap"
                    height="14rem"
                    aria-label="Loading spend chart"
                />
                <VendorSpendStackedChart
                    v-else
                    :points="spendSeries!.points"
                    :days="spendSeries!.days"
                    :period-count="spendSeries!.period_count"
                    :grain="spendSeries!.grain"
                />
            </section>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <section class="snitch-scrap relative space-y-4 p-5 pt-6 sm:p-6">
                    <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                    <h2 class="snitch-display text-2xl text-snitch-ink">Top up credits</h2>
                    <p
                        v-if="!subscription.subscribed"
                        class="text-sm text-snitch-ink/70"
                        data-test="topup-requires-plan"
                    >
                        Top-ups require an active paid plan. Subscribe first, then buy credit packs.
                        Top-up credits expire 3 months after purchase.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Form
                            v-for="pack in creditPacks"
                            :key="pack.key"
                            v-bind="BillingController.checkout.form()"
                            class="border border-snitch-ink/10 p-3"
                        >
                            <input type="hidden" name="product" value="credits" />
                            <input type="hidden" name="pack" :value="pack.key" />
                            <p class="snitch-display text-lg text-snitch-ink">{{ pack.name }}</p>
                            <p class="mb-3 text-sm text-snitch-ink/70">
                                {{ formatCatalogMoney(pack.credits_pence) }} usage balance
                            </p>
                            <button
                                type="submit"
                                class="snitch-btn"
                                :disabled="!pack.has_checkout || !subscription.subscribed"
                                :data-test="`topup-${pack.key}`"
                            >
                                Buy {{ formatCatalogMoney(pack.price_pence) }}
                            </button>
                        </Form>
                    </div>
                </section>

                <section class="snitch-scrap relative space-y-3 p-5 pt-6 sm:p-6">
                    <span class="snitch-tape right-5 -top-2" aria-hidden="true" />
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="snitch-display text-2xl text-snitch-ink">Recent charges</h2>
                        <Link
                            :href="billingCharges()"
                            class="text-sm underline decoration-snitch-spot underline-offset-4"
                            data-test="billing-charges-link"
                        >
                            {{ usage.recent_has_more ? 'View all charges' : 'Charge breakdown' }}
                        </Link>
                    </div>
                    <ul
                        v-if="usage.recent.length"
                        class="divide-y divide-snitch-ink/10 text-sm"
                    >
                        <li
                            v-for="row in usage.recent"
                            :key="row.id"
                            class="flex items-start justify-between gap-3 py-2"
                        >
                            <span class="min-w-0">
                                <span class="snitch-ink-label mr-2 inline-flex items-center gap-1.5">
                                    <img
                                        :src="vendorIconSrc(row.vendor)"
                                        alt=""
                                        class="snitch-platform-logo size-3.5 shrink-0 object-contain"
                                        width="14"
                                        height="14"
                                    >
                                    {{ vendorLabel(row.vendor) }}
                                </span>
                                <span class="text-snitch-ink/90">{{ row.description }}</span>
                                <Link
                                    v-if="rowLinkHref(row)"
                                    :href="rowLinkHref(row)"
                                    class="mt-0.5 block text-xs text-snitch-ink/55 underline decoration-snitch-spot/80 underline-offset-2 hover:text-snitch-ink"
                                >
                                    {{ row.link?.label }}
                                </Link>
                            </span>
                            <span class="shrink-0 tabular-nums text-snitch-ink">
                                {{ formatMoney(row.amount_pence) }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-snitch-ink/60">No usage yet.</p>
                    <p
                        v-if="usage.recent_has_more"
                        class="text-xs text-snitch-ink/50"
                    >
                        Showing latest {{ usage.recent.length }} of {{ usage.recent_total }}.
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>
